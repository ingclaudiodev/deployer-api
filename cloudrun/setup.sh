#!/bin/sh
# Preparación ÚNICA de Cloud Run para deployer-api, por pasos. Cada paso es idempotente (se puede
# repetir) y se corre por separado, en este orden (detalle en cloudrun/README.md):
#
#   sh cloudrun/setup.sh apis        habilita las APIs
#   sh cloudrun/setup.sh iam         cuentas de servicio y permisos
#   sh cloudrun/secrets.sh           (otro script) crea los secretos en Secret Manager
#   sh cloudrun/setup.sh vm          subred de Cloud Run, firewall, la VM deployer-data (PostgreSQL + Redis) y su respaldo
#   sh cloudrun/migrar-bd.sh         (otro script) vuelca la base local y la restaura en la VM
#   sh cloudrun/deploy.sh            (otro script) primera versión de los servicios y jobs
#   sh cloudrun/setup.sh public      abre la API y Reverb a Internet (sin esto responden 403)
#   sh cloudrun/setup.sh scheduler   Cloud Scheduler -> Job deployer-scheduler
#   sh cloudrun/setup.sh domains     muestra los comandos de dominios y DNS (no cambia nada)
#   sh cloudrun/setup.sh retirar     muestra los comandos para borrar deployer-infra y el túnel (no cambia nada)
#
# Requiere gcloud con una cuenta que administre el proyecto: gcloud config set account <tu-cuenta>
set -eu
cd "$(dirname "$0")/.."
. cloudrun/config.sh
G="gcloud --project=$PROJECT"

exists() { "$@" >/dev/null 2>&1; }

case "${1:-}" in
apis)
  $G services enable run.googleapis.com secretmanager.googleapis.com compute.googleapis.com \
    cloudscheduler.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com \
    aiplatform.googleapis.com
  ;;

iam)
  # deployer-api-run: identidad de los servicios y jobs de Cloud Run. Lee secretos y es quien
  # Cloud Scheduler usa para ejecutar el job del planificador.
  exists $G iam service-accounts describe "$RUN_SA_EMAIL" \
    || $G iam service-accounts create "$RUN_SA" --display-name="deployer-api en Cloud Run"
  # deployer-infra-vm: identidad de la VM de datos. Solo lee sus dos secretos (clave de Redis y
  # de la base; secrets.sh le da acceso) y escribe logs.
  exists $G iam service-accounts describe "$VM_SA_EMAIL" \
    || $G iam service-accounts create "$VM_SA" --display-name="VM de datos de deployer (PostgreSQL + Redis)"
  # Una cuenta recién creada tarda unos segundos en ser visible para IAM ("does not exist"):
  # se reintenta en vez de fallar.
  n=0
  until $G projects add-iam-policy-binding "$PROJECT" --member="serviceAccount:$VM_SA_EMAIL" \
      --role=roles/logging.logWriter --condition=None --quiet >/dev/null 2>&1; do
    n=$((n + 1))
    if [ "$n" -ge 6 ]; then
      echo "No se pudo dar roles/logging.logWriter a $VM_SA_EMAIL; repite: sh cloudrun/setup.sh iam" >&2; exit 1
    fi
    echo "  IAM aún no ve la cuenta nueva; reintento $n/5 en 10 s"; sleep 10
  done
  # Vertex AI (Gemini): la app llama al modelo como deployer-api-run, sin llaves ni archivos.
  $G projects add-iam-policy-binding "$PROJECT" --member="serviceAccount:$RUN_SA_EMAIL" \
    --role=roles/aiplatform.user --condition=None --quiet >/dev/null
  echo "Cuentas de servicio listas: $RUN_SA_EMAIL, $VM_SA_EMAIL"
  ;;

vm)
  # IP interna fija: es DB_HOST y REDIS_HOST (deploy.sh la lee de aquí, no está escrita en el repo).
  exists $G compute addresses describe "$INFRA_IP_NAME" --region="$REGION" \
    || $G compute addresses create "$INFRA_IP_NAME" --region="$REGION" --subnet="$SUBNET"
  INFRA_IP=$($G compute addresses describe "$INFRA_IP_NAME" --region="$REGION" --format='value(address)')
  # Subred exclusiva de Cloud Run: su rango es el único origen que admiten el firewall y pg_hba.
  exists $G compute networks subnets describe "$RUN_SUBNET" --region="$REGION" \
    || $G compute networks subnets create "$RUN_SUBNET" --network="$NETWORK" --region="$REGION" --range="$RUN_SUBNET_RANGE"
  RUN_CIDR=$($G compute networks subnets describe "$RUN_SUBNET" --region="$REGION" --format='value(ipCidrRange)')
  SECRET_DB_PASSWORD=$(grep -E '^DB_PASSWORD=' cloudrun/env/secrets.map | cut -d= -f2)
  for name in "$SECRET_DB_PASSWORD" "$SECRET_REDIS_PASSWORD"; do
    exists $G secrets describe "$name" || {
      echo "Falta el secreto $name: corre antes 'sh cloudrun/secrets.sh' (el arranque de la VM lo lee)." >&2; exit 1; }
  done

  # Postgres (5432) y Redis (6379): solo desde la subred exclusiva de Cloud Run. Son dos reglas:
  # la de prioridad 900 niega esos puertos a todos (también al resto de la red, que si no entraría
  # por default-allow-internal, 10.128.0.0/9) y la de 800 (gana el número menor) los abre solo al
  # rango de Cloud Run. Se filtra por rango porque Google Cloud no admite la etiqueta de red de
  # Cloud Run ($RUN_TAG) en reglas de entrada: en la VM anterior esa regla no dejaba pasar a la
  # API y hubo que desactivar la de negación. Desde Internet no entra nada a esos puertos: la IP
  # pública de la VM es solo para salir (apt).
  if exists $G compute firewall-rules describe "$VM_TAG-backends"; then
    $G compute firewall-rules update "$VM_TAG-backends" --source-ranges="$RUN_CIDR"
  else
    $G compute firewall-rules create "$VM_TAG-backends" --network="$NETWORK" --priority=800 \
      --direction=INGRESS --allow=tcp:5432,tcp:6379 --source-ranges="$RUN_CIDR" --target-tags="$VM_TAG" \
      --description="Subred de Cloud Run ($RUN_SUBNET) -> PostgreSQL y Redis de $VM_NAME"
  fi
  exists $G compute firewall-rules describe "$VM_TAG-backends-deny" \
    || $G compute firewall-rules create "$VM_TAG-backends-deny" --network="$NETWORK" --priority=900 \
         --direction=INGRESS --action=DENY --rules=tcp:5432,tcp:6379 --source-ranges=0.0.0.0/0 --target-tags="$VM_TAG" \
         --description="PostgreSQL y Redis de $VM_NAME: solo la subred de Cloud Run ($VM_TAG-backends)"
  # SSH (22): solo por IAP (gcloud compute ssh --tunnel-through-iap, como hacen migrar-bd.sh y el
  # README). La red `default` trae default-allow-ssh abierto a Internet: la regla de prioridad 900
  # se lo niega a esta VM y la de 800 deja pasar solo al rango de IAP.
  exists $G compute firewall-rules describe "$VM_TAG-ssh-iap" \
    || $G compute firewall-rules create "$VM_TAG-ssh-iap" --network="$NETWORK" --priority=800 \
         --direction=INGRESS --allow=tcp:22 --source-ranges="$IAP_RANGE" --target-tags="$VM_TAG" \
         --description="SSH a $VM_NAME solo por IAP"
  exists $G compute firewall-rules describe "$VM_TAG-ssh-deny" \
    || $G compute firewall-rules create "$VM_TAG-ssh-deny" --network="$NETWORK" --priority=900 \
         --direction=INGRESS --action=DENY --rules=tcp:22 --source-ranges=0.0.0.0/0 --target-tags="$VM_TAG" \
         --description="SSH a $VM_NAME: solo IAP ($VM_TAG-ssh-iap)"

  METADATA="redis-maxmemory=$REDIS_MAXMEMORY,secret-redis-password=$SECRET_REDIS_PASSWORD,secret-db-password=$SECRET_DB_PASSWORD,db-name=$DB_NAME,db-user=$DB_SUPERUSER,db-allowed-cidr=$RUN_CIDR,postgres-version=$POSTGRES_VERSION,postgres-shared-buffers=$POSTGRES_SHARED_BUFFERS"
  if exists $G compute instances describe "$VM_NAME" --zone="$ZONE"; then
    $G compute instances add-metadata "$VM_NAME" --zone="$ZONE" --metadata="$METADATA" \
      --metadata-from-file=startup-script=cloudrun/infra/vm-startup.sh
    echo "La VM ya existía: metadatos actualizados. Para reaplicar el script de arranque:"
    echo "  gcloud compute ssh $VM_NAME --project=$PROJECT --zone=$ZONE --tunnel-through-iap --command='sudo google_metadata_script_runner startup'"
  else
    # Sin `address=`: IP pública efímera, solo de salida. Nada apunta a ella, así que no se reserva.
    $G compute instances create "$VM_NAME" --zone="$ZONE" --machine-type="$VM_MACHINE_TYPE" \
      --image-family="$VM_IMAGE_FAMILY" --image-project="$VM_IMAGE_PROJECT" \
      --boot-disk-size="$VM_DISK_SIZE" --boot-disk-type="$VM_DISK_TYPE" \
      --network-interface="subnet=$SUBNET,private-network-ip=$INFRA_IP" \
      --tags="$VM_TAG" --service-account="$VM_SA_EMAIL" --scopes=cloud-platform \
      --shielded-secure-boot --shielded-vtpm --shielded-integrity-monitoring \
      --metadata="$METADATA" --metadata-from-file=startup-script=cloudrun/infra/vm-startup.sh
  fi

  # Respaldo: snapshot diario del disco de arranque (lleva el mismo nombre que la VM), guardado
  # $BACKUP_RETENTION_DAYS días. Los snapshots sobreviven aunque se borre el disco.
  exists $G compute resource-policies describe "$BACKUP_POLICY" --region="$REGION" \
    || $G compute resource-policies create snapshot-schedule "$BACKUP_POLICY" --region="$REGION" \
         --daily-schedule --start-time="$BACKUP_START_TIME" --max-retention-days="$BACKUP_RETENTION_DAYS" \
         --on-source-disk-delete=keep-auto-snapshots --storage-location="$REGION" \
         --description="Respaldo diario de $VM_NAME (PostgreSQL + Redis de deployer-api)"
  $G compute disks describe "$VM_NAME" --zone="$ZONE" --format='value(resourcePolicies)' | grep -q "/$BACKUP_POLICY\$" \
    || $G compute disks add-resource-policies "$VM_NAME" --zone="$ZONE" --resource-policies="$BACKUP_POLICY"

  cat <<EOF

VM $VM_NAME ($VM_MACHINE_TYPE): IP interna $INFRA_IP (DB_HOST y REDIS_HOST).
PostgreSQL y Redis: solo desde la subred de Cloud Run $RUN_SUBNET ($RUN_CIDR). SSH: solo por IAP.
Respaldo: snapshot diario a las $BACKUP_START_TIME UTC, $BACKUP_RETENTION_DAYS días (política $BACKUP_POLICY).
El arranque instala PostgreSQL $POSTGRES_VERSION y Redis (un par de minutos). Termina cuando el log dice "listos":
  gcloud compute instances get-serial-port-output $VM_NAME --project=$PROJECT --zone=$ZONE | grep startup-script | tail -20
La base $DB_NAME nace VACÍA. Para llevar los datos del Postgres local:  sh cloudrun/migrar-bd.sh
EOF
  ;;

public)
  # API pública: la autenticación la hace Laravel (Passport), no IAM. Lo mismo el WebSocket.
  for s in "$API_SERVICE" "$REVERB_SERVICE"; do
    $G run services add-iam-policy-binding "$s" --region="$REGION" \
      --member=allUsers --role=roles/run.invoker --quiet >/dev/null
    echo "$s: $($G run services describe "$s" --region="$REGION" --format='value(status.url)')"
  done
  ;;

scheduler)
  $G run jobs add-iam-policy-binding "$SCHEDULER_JOB" --region="$REGION" \
    --member="serviceAccount:$RUN_SA_EMAIL" --role=roles/run.invoker --quiet >/dev/null
  URI="https://run.googleapis.com/v2/projects/$PROJECT/locations/$REGION/jobs/$SCHEDULER_JOB:run"
  if exists $G scheduler jobs describe "$SCHEDULER_JOB" --location="$REGION"; then ACTION=update; else ACTION=create; fi
  $G scheduler jobs $ACTION http "$SCHEDULER_JOB" --location="$REGION" \
    --schedule="$SCHEDULE" --time-zone=Etc/UTC \
    --uri="$URI" --http-method=POST --oauth-service-account-email="$RUN_SA_EMAIL" \
    --description="php artisan schedule:run de deployer-api (Cloud Run Job $SCHEDULER_JOB)"
  echo "Cloud Scheduler '$SCHEDULER_JOB' ($SCHEDULE UTC) -> Job $SCHEDULER_JOB"
  ;;

domains)
  cat <<EOF
Este paso NO ejecuta nada: imprime los cambios de DNS (zona deployercl de Cloud DNS). Hacerlo
cuando los servicios ya respondan por su URL *.run.app.
Si un registro ya no existe en la zona, usar \`create\` en vez de \`delete\` + \`create\`.

API y WebSocket (asignación de dominio de Cloud Run: gratis, certificado administrado):
   gcloud beta run domain-mappings create --project=$PROJECT --region=$REGION --service=$API_SERVICE --domain=$API_DOMAIN
   gcloud beta run domain-mappings create --project=$PROJECT --region=$REGION --service=$REVERB_SERVICE --domain=$WS_DOMAIN
   # cada comando imprime el registro a crear (CNAME ghs.googlehosted.com.). Reemplazar los A actuales:
   gcloud dns record-sets delete $API_DOMAIN. --project=$PROJECT --zone=deployercl --type=A
   gcloud dns record-sets create $API_DOMAIN. --project=$PROJECT --zone=deployercl --type=CNAME --ttl=300 --rrdatas=ghs.googlehosted.com.
   gcloud dns record-sets delete $WS_DOMAIN. --project=$PROJECT --zone=deployercl --type=A
   gcloud dns record-sets create $WS_DOMAIN. --project=$PROJECT --zone=deployercl --type=CNAME --ttl=300 --rrdatas=ghs.googlehosted.com.
   # estado del certificado (unos 15 minutos, a veces más):
   gcloud beta run domain-mappings describe --project=$PROJECT --region=$REGION --domain=$API_DOMAIN
EOF
  ;;

retirar)
  # Nombres de la instalación anterior (VM e2-micro con el túnel chisel + Redis). Fijos a propósito:
  # ya no están en config.sh.
  cat <<EOF
Este paso NO ejecuta nada: imprime cómo borrar la VM anterior (deployer-infra) y el túnel al
Postgres del Mac. Correrlo SOLO cuando la API ya funcione contra $VM_NAME (README, "Traspaso").
Borrar la VM es irreversible: se pierde su Redis (sesiones y colas que no se hayan traspasado).

1. Confirmar que nada usa ya la VM anterior: cada línea debe terminar en 0 (10.128.0.3 era su IP interna).
   for s in \$(gcloud run services list --project=$PROJECT --region=$REGION --format='value(metadata.name)'); do printf '%s: ' \$s; gcloud run services describe \$s --project=$PROJECT --region=$REGION --format=yaml | grep -c '10\.128\.0\.3'; done
   for j in \$(gcloud run jobs list --project=$PROJECT --region=$REGION --format='value(metadata.name)'); do printf 'job %s: ' \$j; gcloud run jobs describe \$j --project=$PROJECT --region=$REGION --format=yaml | grep -c '10\.128\.0\.3'; done

2. VM, sus IP reservadas (la pública se cobra aunque quede suelta) y sus reglas de firewall:
   gcloud compute instances delete deployer-infra --project=$PROJECT --zone=$ZONE
   gcloud compute addresses delete deployer-infra-ip deployer-infra-public-ip --project=$PROJECT --region=$REGION
   gcloud compute firewall-rules delete deployer-infra-tunnel deployer-infra-backends deployer-infra-backends-deny --project=$PROJECT

3. DNS y secretos del túnel:
   gcloud dns record-sets delete db-tunnel.deployer.cl. --project=$PROJECT --zone=deployercl --type=A
   gcloud secrets delete deployer-db-tunnel-users --project=$PROJECT
   gcloud secrets delete deployer-db-tunnel-key --project=$PROJECT

4. Cliente del túnel en el Mac (LaunchAgent cl.deployer.db-tunnel):
   launchctl bootout gui/\$(id -u)/cl.deployer.db-tunnel
   rm ~/Library/LaunchAgents/cl.deployer.db-tunnel.plist
   rm -r ~/.config/deployer-db-tunnel
EOF
  ;;

*)
  sed -n '2,15p' "$0"; exit 1 ;;
esac
