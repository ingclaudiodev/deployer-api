#!/bin/sh
# Comprueba, sin cambiar nada, lo que necesitan la conexión con GitHub, el bucket y los despliegues
# de proyectos: cuenta de servicio deployer-factory y sus permisos, bucket, .env local, migraciones
# y tests. Cada línea termina en OK o FALTA; al final dice cuántas faltan.
# Uso (desde cualquier parte): sh cloudrun/verificar-despliegues.sh
#   SIN_TESTS=1   no corre los tests de la API
set -u
cd "$(dirname "$0")/.."
. cloudrun/config.sh

ORG=$(grep -E '^DEPLOYER_ORGANIZATION_ID=' .env | tail -1 | cut -d= -f2-)
BILLING=$(grep -E '^DEPLOYER_BILLING_ACCOUNT=' .env | tail -1 | cut -d= -f2-)
BUCKET=$(grep -E '^PROJECT_STORAGE_BUCKET=' .env | tail -1 | cut -d= -f2-)
FACTORY=deployer-factory@$PROJECT.iam.gserviceaccount.com
FALTAN=0

ok()    { printf '  OK     %s\n' "$1"; }
falta() { printf '  FALTA  %s\n' "$1"; FALTAN=$((FALTAN + 1)); }
# check <descripción> <comando...>: OK si el comando imprime algo y termina bien.
check() {
  desc=$1; shift
  out=$("$@" 2>/dev/null </dev/null) && [ -n "$out" ] && ok "$desc" || falta "$desc"
}
# rol <descripción> <rol> <miembro> <comando get-iam-policy...>
rol() {
  desc=$1; role=$2; member=$3; shift 3
  check "$desc" "$@" --flatten='bindings[].members' \
    --filter="bindings.role=$role AND bindings.members=serviceAccount:$member" --format='value(bindings.role)'
}

# rol_bucket <descripción> <rol> <miembro>: lo mismo para el bucket. Su get-iam-policy se lee como
# JSON completo y se busca aquí, sin depender de --flatten/--filter.
rol_bucket() {
  if gcloud storage buckets get-iam-policy "gs://$BUCKET" --format=json 2>/dev/null </dev/null \
    | ROLE="$2" MEMBER="serviceAccount:$3" python3 -c '
import json, os, sys
policy = json.load(sys.stdin)
found = any(b.get("role") == os.environ["ROLE"] and os.environ["MEMBER"] in b.get("members", []) for b in policy.get("bindings", []))
sys.exit(0 if found else 1)' 2>/dev/null; then ok "$1"; else falta "$1"; fi
}

echo "== .env local"
for key in GITHUB_CLIENT_ID GITHUB_CLIENT_SECRET PROJECT_STORAGE_BUCKET PROJECT_STORAGE_PROJECT \
  DEPLOYER_ORGANIZATION_ID DEPLOYER_BILLING_ACCOUNT DEPLOYMENT_PROJECT DEPLOYMENT_SERVICE_ACCOUNT VERTEX_AI_PROJECT; do
  grep -Eq "^$key=.+" .env && ok "$key tiene valor" || falta "$key está vacío o no existe en .env"
done
[ "$(grep -E '^REDIS_QUEUE_RETRY_AFTER=' .env | tail -1 | cut -d= -f2-)" = "600" ] \
  && ok "REDIS_QUEUE_RETRY_AFTER=600" || falta "REDIS_QUEUE_RETRY_AFTER debe ser 600"

echo "== cuenta de servicio de despliegues ($FACTORY)"
check "existe" gcloud iam service-accounts describe "$FACTORY" --project="$PROJECT" --format='value(email)'
rol "crea proyectos en la organización $ORG" roles/resourcemanager.projectCreator "$FACTORY" \
  gcloud organizations get-iam-policy "$ORG"
rol "enlaza la cuenta de facturación $BILLING" roles/billing.user "$FACTORY" \
  gcloud billing accounts get-iam-policy "$BILLING"
rol "escribe logs en $PROJECT" roles/logging.logWriter "$FACTORY" \
  gcloud projects get-iam-policy "$PROJECT"
rol_bucket "lee y escribe en gs://$BUCKET" roles/storage.objectUser "$FACTORY"

echo "== la API ($RUN_SA_EMAIL) puede lanzar despliegues"
rol "crea builds en $PROJECT" roles/cloudbuild.builds.editor "$RUN_SA_EMAIL" \
  gcloud projects get-iam-policy "$PROJECT"
rol "actúa como deployer-factory" roles/iam.serviceAccountUser "$RUN_SA_EMAIL" \
  gcloud iam service-accounts get-iam-policy "$FACTORY" --project="$PROJECT"
rol_bucket "escribe en gs://$BUCKET" roles/storage.objectAdmin "$RUN_SA_EMAIL"

echo "== proyecto y bucket"
check "API de Cloud Build habilitada" gcloud services list --enabled --project="$PROJECT" \
  --filter='config.name=cloudbuild.googleapis.com' --format='value(config.name)'
gcloud storage buckets describe "gs://$BUCKET" --format=json 2>/dev/null </dev/null \
  | grep -Eiq '"versioning(_enabled)?": *(true|\{)' \
  && ok "versionado de objetos activo" || falta "versionado de objetos del bucket (gcloud storage buckets update gs://$BUCKET --versioning)"

echo "== secretos de producción (los crea: sh cloudrun/secrets.sh)"
for name in deployer-github-client-id deployer-github-client-secret; do
  check "$name" gcloud secrets describe "$name" --project="$PROJECT" --format='value(name)'
done

echo "== migraciones (base local)"
STATUS=$(php artisan migrate:status 2>&1 </dev/null)
for m in create_repository_connections_table add_approval_to_projects_table; do
  printf '%s\n' "$STATUS" | grep "$m" | grep -q 'Ran' && ok "$m aplicada" || falta "$m sin aplicar (php artisan migrate)"
done

if [ "${SIN_TESTS:-0}" != "1" ]; then
  echo "== tests de la API"
  if php artisan test --compact tests/Feature/Repositories tests/Feature/Projects tests/Feature/Ai </dev/null; then
    ok "tests"
  else
    falta "tests (el detalle está arriba)"
  fi
fi

echo
[ "$FALTAN" -eq 0 ] && echo "Todo en orden." || echo "Faltan $FALTAN cosas: las líneas marcadas FALTA."
exit "$FALTAN"
