#!/bin/sh
# Nombres y ubicaciones que comparten setup.sh, secrets.sh, deploy.sh y render.sh. Nada de esto es
# secreto. Cualquier valor se puede cambiar por entorno: REGION=southamerica-west1 sh cloudrun/deploy.sh

PROJECT=${PROJECT:-claudio-orellana-services}
REGION=${REGION:-us-central1}
ZONE=${ZONE:-us-central1-a}

# Red por la que Cloud Run llega a la VM (Direct VPC egress: sin conector, solo se paga el tráfico).
NETWORK=${NETWORK:-default}
SUBNET=${SUBNET:-default}                                         # subred de la VM
# Subred EXCLUSIVA de Cloud Run: sus instancias toman de ella la IP con la que entran a la VPC, y el
# firewall y pg_hba de la VM solo admiten este rango. Nada más debe usarla (ni VMs ni otros
# servicios): es lo que hace cierto "solo Cloud Run llega a la base". Las reglas de firewall de
# entrada no pueden filtrar por la etiqueta de red de Cloud Run, solo por rango. Cloud Run exige
# /26 o mayor, reserva las IP en bloques de 16 y las retiene hasta 20 minutos tras cada revisión:
# un /24 da margen para varios despliegues seguidos y no cuesta más. Fuera de 10.128.0.0/9, que
# la red `default` reserva para sus subredes automáticas.
RUN_SUBNET=${RUN_SUBNET:-deployer-run}
RUN_SUBNET_RANGE=${RUN_SUBNET_RANGE:-10.8.0.0/24}
# Único origen admitido para SSH a la VM: el reenvío TCP de IAP (rango fijo de Google).
IAP_RANGE=${IAP_RANGE:-35.235.240.0/20}

# Imagen: la misma de siempre (cloudbuild.yaml, docker/Dockerfile target `app`).
IMAGE_REPO=${IMAGE_REPO:-us-central1-docker.pkg.dev/$PROJECT/deployer/deployer-api}

# Cloud Run.
API_SERVICE=${API_SERVICE:-deployer-api}
REVERB_SERVICE=${REVERB_SERVICE:-deployer-reverb}
MIGRATE_JOB=${MIGRATE_JOB:-deployer-migrate}
SCHEDULER_JOB=${SCHEDULER_JOB:-deployer-scheduler}
RUN_SA=${RUN_SA:-deployer-api-run}
RUN_SA_EMAIL=$RUN_SA@$PROJECT.iam.gserviceaccount.com
# Etiqueta de red de las instancias de Cloud Run. Informativa: el firewall de la VM filtra por
# RUN_SUBNET_RANGE, no por ella.
RUN_TAG=${RUN_TAG:-deployer-run}

# Cada cuánto Cloud Scheduler lanza `schedule:run` (zona horaria UTC, igual que la app). Debe caer
# en múltiplos de 5 minutos e incluir las 00:00 o las tareas diarias no corren nunca.
SCHEDULE=${SCHEDULE:-*/5 * * * *}

# VM de datos: PostgreSQL (la base de producción) + Redis. Reemplaza a deployer-infra (e2-micro con
# el túnel chisel al Postgres del Mac + Redis). Lleva nombres propios para poder convivir con la
# anterior hasta terminar el traspaso (README, "Traspaso desde deployer-infra").
VM_NAME=${VM_NAME:-deployer-data}
VM_MACHINE_TYPE=${VM_MACHINE_TYPE:-e2-small}                      # 2 GB de RAM, 0,5 vCPU sostenida
VM_IMAGE_FAMILY=${VM_IMAGE_FAMILY:-ubuntu-2604-lts-amd64}         # trae postgresql-18 y redis 8
VM_IMAGE_PROJECT=${VM_IMAGE_PROJECT:-ubuntu-os-cloud}
VM_DISK_SIZE=${VM_DISK_SIZE:-20GB}
VM_DISK_TYPE=${VM_DISK_TYPE:-pd-balanced}                         # SSD: cuenta en la cuota SSD_TOTAL_GB
# La cuenta de servicio se reutiliza (ya existe y ya lee la clave de Redis); el nombre es histórico.
VM_SA=${VM_SA:-deployer-infra-vm}
VM_SA_EMAIL=$VM_SA@$PROJECT.iam.gserviceaccount.com
VM_TAG=${VM_TAG:-deployer-data}
INFRA_IP_NAME=${INFRA_IP_NAME:-deployer-data-ip}                  # IP interna fija (DB_HOST, REDIS_HOST)
REDIS_MAXMEMORY=${REDIS_MAXMEMORY:-256mb}
# Respaldo: snapshot diario del disco de la VM (base, AOF de Redis y sistema). La hora es UTC.
BACKUP_POLICY=${BACKUP_POLICY:-deployer-data-diario}
BACKUP_START_TIME=${BACKUP_START_TIME:-08:00}
BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-7}
# Base y superusuario que crea la VM. Deben coincidir con DB_DATABASE y DB_USERNAME de env/common.env;
# la clave es el secreto de DB_PASSWORD (env/secrets.map).
DB_NAME=${DB_NAME:-deployer}
DB_SUPERUSER=${DB_SUPERUSER:-claudio}
POSTGRES_VERSION=${POSTGRES_VERSION:-18}                          # no menor que la del Postgres de origen
POSTGRES_SHARED_BUFFERS=${POSTGRES_SHARED_BUFFERS:-256MB}

# Dominios públicos (no cambian respecto de Kubernetes).
API_DOMAIN=${API_DOMAIN:-backend.deployer.cl}
WS_DOMAIN=${WS_DOMAIN:-ws.deployer.cl}

# Secreto de Secret Manager que solo usa la VM (lo de la app está en env/secrets.map).
SECRET_REDIS_PASSWORD=${SECRET_REDIS_PASSWORD:-deployer-redis-password}
