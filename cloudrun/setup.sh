#!/bin/sh
# Preparación ÚNICA de Cloud Run para deployer-api, por pasos. Cada paso es idempotente (se puede
# repetir) y se corre por separado, en este orden (detalle en cloudrun/README.md):
#
#   sh cloudrun/setup.sh apis        habilita las APIs
#   sh cloudrun/setup.sh iam         cuentas de servicio y permisos
#   sh cloudrun/secrets.sh           (otro script) copia los secretos del clúster a Secret Manager
#   sh cloudrun/setup.sh vm          IPs fijas, firewall y la VM deployer-infra (túnel + Redis)
#   sh cloudrun/deploy.sh            (otro script) primera versión de los servicios y jobs
#   sh cloudrun/setup.sh public      abre la API y Reverb a Internet (sin esto responden 403)
#   sh cloudrun/setup.sh scheduler   Cloud Scheduler -> Job deployer-scheduler
#   sh cloudrun/setup.sh domains     muestra los comandos de dominios y DNS (no cambia nada)
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
    cloudscheduler.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com
  ;;

iam)
  # deployer-api-run: identidad de los servicios y jobs de Cloud Run. Lee secretos y es quien
  # Cloud Scheduler usa para ejecutar el job del planificador.
  exists $G iam service-accounts describe "$RUN_SA_EMAIL" \
    || $G iam service-accounts create "$RUN_SA" --display-name="deployer-api en Cloud Run"
  # deployer-infra-vm: identidad de la VM. Solo lee sus tres secretos (secrets.sh le da acceso) y
  # escribe logs.
  exists $G iam service-accounts describe "$VM_SA_EMAIL" \
    || $G iam service-accounts create "$VM_SA" --display-name="VM deployer-infra (túnel + Redis)"
  $G projects add-iam-policy-binding "$PROJECT" --member="serviceAccount:$VM_SA_EMAIL" \
    --role=roles/logging.logWriter --condition=None --quiet >/dev/null
  echo "Cuentas de servicio listas: $RUN_SA_EMAIL, $VM_SA_EMAIL"
  ;;

vm)
  # IP interna fija: es DB_HOST y REDIS_HOST (deploy.sh la lee de aquí, no está escrita en el repo).
  exists $G compute addresses describe "$INFRA_IP_NAME" --region="$REGION" \
    || $G compute addresses create "$INFRA_IP_NAME" --region="$REGION" --subnet="$SUBNET"
  # IP pública fija: a ella apunta el registro A de $TUNNEL_DOMAIN.
  exists $G compute addresses describe "$INFRA_PUBLIC_IP_NAME" --region="$REGION" \
    || $G compute addresses create "$INFRA_PUBLIC_IP_NAME" --region="$REGION"
  INFRA_IP=$($G compute addresses describe "$INFRA_IP_NAME" --region="$REGION" --format='value(address)')
  PUBLIC_IP=$($G compute addresses describe "$INFRA_PUBLIC_IP_NAME" --region="$REGION" --format='value(address)')

  # Internet solo entra al 443 (WebSocket TLS del túnel y el desafío de Let's Encrypt).
  exists $G compute firewall-rules describe deployer-infra-tunnel \
    || $G compute firewall-rules create deployer-infra-tunnel --network="$NETWORK" \
         --direction=INGRESS --allow=tcp:443 --source-ranges=0.0.0.0/0 --target-tags="$VM_TAG" \
         --description="Túnel chisel del Postgres del Mac (deployer-api)"
  # Postgres (5432, el extremo del túnel) y Redis (6379): solo desde las instancias de Cloud Run.
  exists $G compute firewall-rules describe deployer-infra-backends \
    || $G compute firewall-rules create deployer-infra-backends --network="$NETWORK" \
         --direction=INGRESS --allow=tcp:5432,tcp:6379 --source-tags="$RUN_TAG" --target-tags="$VM_TAG" \
         --description="Cloud Run (deployer-api) -> Postgres por túnel y Redis"

  # e2-micro + disco estándar de 10 GB: dentro de la capa gratuita de Compute Engine en esta
  # región. pd-standard no consume la cuota SSD_TOTAL_GB (la que limitaba al clúster).
  if exists $G compute instances describe "$VM_NAME" --zone="$ZONE"; then
    $G compute instances add-metadata "$VM_NAME" --zone="$ZONE" \
      --metadata="tunnel-domain=$TUNNEL_DOMAIN,redis-maxmemory=$REDIS_MAXMEMORY,secret-tunnel-users=$SECRET_TUNNEL_USERS,secret-tunnel-key=$SECRET_TUNNEL_KEY,secret-redis-password=$SECRET_REDIS_PASSWORD" \
      --metadata-from-file=startup-script=cloudrun/infra/vm-startup.sh
    echo "La VM ya existía: metadatos actualizados. Para reaplicar el script de arranque:"
    echo "  gcloud compute ssh $VM_NAME --project=$PROJECT --zone=$ZONE --tunnel-through-iap --command='sudo google_metadata_script_runner startup'"
  else
    $G compute instances create "$VM_NAME" --zone="$ZONE" --machine-type=e2-micro \
      --image-family=debian-12 --image-project=debian-cloud \
      --boot-disk-size=10GB --boot-disk-type=pd-standard \
      --network-interface="subnet=$SUBNET,private-network-ip=$INFRA_IP,address=$PUBLIC_IP" \
      --tags="$VM_TAG" --service-account="$VM_SA_EMAIL" --scopes=cloud-platform \
      --shielded-secure-boot --shielded-vtpm --shielded-integrity-monitoring \
      --metadata="tunnel-domain=$TUNNEL_DOMAIN,redis-maxmemory=$REDIS_MAXMEMORY,secret-tunnel-users=$SECRET_TUNNEL_USERS,secret-tunnel-key=$SECRET_TUNNEL_KEY,secret-redis-password=$SECRET_REDIS_PASSWORD" \
      --metadata-from-file=startup-script=cloudrun/infra/vm-startup.sh
  fi
  cat <<EOF

VM $VM_NAME: IP interna $INFRA_IP (DB_HOST y REDIS_HOST), IP pública $PUBLIC_IP.
Log del arranque:  gcloud compute instances get-serial-port-output $VM_NAME --project=$PROJECT --zone=$ZONE | grep startup-script
El servidor chisel NO obtiene su certificado hasta que $TUNNEL_DOMAIN apunte a $PUBLIC_IP
(paso "domains"): ese cambio de DNS es el que mueve el túnel del clúster a la VM.
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
  PUBLIC_IP=$($G compute addresses describe "$INFRA_PUBLIC_IP_NAME" --region="$REGION" --format='value(address)' 2>/dev/null || echo '<IP pública de la VM: correr antes el paso vm>')
  cat <<EOF
Este paso NO ejecuta nada: son los cambios que mueven el tráfico real del clúster a Cloud Run.
Hacerlos en este orden, cuando los servicios ya respondan por su URL *.run.app (README, "Cutover").
La zona de Cloud DNS es deployercl; anota los registros actuales antes de cambiarlos (rollback).

1. Túnel de la base de datos (mueve el Postgres del clúster a la VM; el Mac no se toca):
   gcloud dns record-sets update $TUNNEL_DOMAIN. --project=$PROJECT --zone=deployercl --type=A --ttl=300 --rrdatas=$PUBLIC_IP

2. API y WebSocket (asignación de dominio de Cloud Run: gratis, certificado administrado):
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

*)
  sed -n '2,14p' "$0"; exit 1 ;;
esac
