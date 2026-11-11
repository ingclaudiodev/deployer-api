#!/bin/sh
# Nombres y ubicaciones que comparten setup.sh, secrets.sh, deploy.sh y render.sh. Nada de esto es
# secreto. Cualquier valor se puede cambiar por entorno: REGION=southamerica-west1 sh cloudrun/deploy.sh
# (ojo: la VM e2-micro solo es gratuita en us-west1, us-central1 y us-east1).

PROJECT=${PROJECT:-claudio-orellana-services}
REGION=${REGION:-us-central1}
ZONE=${ZONE:-us-central1-a}

# Red por la que Cloud Run llega a la VM (Direct VPC egress: sin conector, solo se paga el tráfico).
NETWORK=${NETWORK:-default}
SUBNET=${SUBNET:-default}

# Imagen: la misma de siempre (cloudbuild.yaml, docker/Dockerfile target `app`).
IMAGE_REPO=${IMAGE_REPO:-us-central1-docker.pkg.dev/$PROJECT/deployer/deployer-api}

# Cloud Run.
API_SERVICE=${API_SERVICE:-deployer-api}
REVERB_SERVICE=${REVERB_SERVICE:-deployer-reverb}
MIGRATE_JOB=${MIGRATE_JOB:-deployer-migrate}
SCHEDULER_JOB=${SCHEDULER_JOB:-deployer-scheduler}
RUN_SA=${RUN_SA:-deployer-api-run}
RUN_SA_EMAIL=$RUN_SA@$PROJECT.iam.gserviceaccount.com
# Etiqueta de red de las instancias de Cloud Run: es el origen que admite el firewall de la VM.
RUN_TAG=${RUN_TAG:-deployer-run}

# Cada cuánto Cloud Scheduler lanza `schedule:run` (zona horaria UTC, igual que la app). Debe caer
# en múltiplos de 5 minutos e incluir las 00:00 o las tareas diarias no corren nunca.
SCHEDULE=${SCHEDULE:-*/5 * * * *}

# VM de infraestructura: servidor chisel (túnel al Postgres del Mac) + Redis.
VM_NAME=${VM_NAME:-deployer-infra}
VM_SA=${VM_SA:-deployer-infra-vm}
VM_SA_EMAIL=$VM_SA@$PROJECT.iam.gserviceaccount.com
VM_TAG=${VM_TAG:-deployer-infra}
INFRA_IP_NAME=${INFRA_IP_NAME:-deployer-infra-ip}                # IP interna fija (DB_HOST, REDIS_HOST)
INFRA_PUBLIC_IP_NAME=${INFRA_PUBLIC_IP_NAME:-deployer-infra-public-ip}   # IP pública fija (túnel)
REDIS_MAXMEMORY=${REDIS_MAXMEMORY:-256mb}

# Dominios públicos (no cambian respecto de Kubernetes: el frontend y el Mac siguen igual).
API_DOMAIN=${API_DOMAIN:-backend.deployer.cl}
WS_DOMAIN=${WS_DOMAIN:-ws.deployer.cl}
TUNNEL_DOMAIN=${TUNNEL_DOMAIN:-db-tunnel.deployer.cl}

# Nombres en Secret Manager de lo que solo usa la VM (lo de la app está en env/secrets.map).
SECRET_TUNNEL_USERS=${SECRET_TUNNEL_USERS:-deployer-db-tunnel-users}
SECRET_TUNNEL_KEY=${SECRET_TUNNEL_KEY:-deployer-db-tunnel-key}
SECRET_REDIS_PASSWORD=${SECRET_REDIS_PASSWORD:-deployer-redis-password}
