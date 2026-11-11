#!/bin/sh
# Despliegue versionado de deployer-api en Cloud Run. Mismo contrato que k8s/deploy.sh: la versión
# de la imagen es la de version.txt (semver), nunca :latest. Flujo:
#   1. lee version.txt y el sha corto; exige árbol commiteado
#   2. si la versión no existe en Artifact Registry la construye con Cloud Build (cloudbuild.yaml);
#      si ya existe la reutiliza tal cual (los tags son inmutables). Reconstruirla a propósito: FORCE=1
#   3. renderiza cloudrun/{services,jobs}/*.yaml con cloudrun/env/* (render.sh)
#   4. actualiza el Job de migraciones y lo ejecuta; si falla, no se despliega nada
#   5. reemplaza el Job del planificador y los servicios API (Octane + Horizon) y Reverb
# Uso (desde cualquier parte): sh cloudrun/deploy.sh
#   BUILD_ONLY=1    solo construir la imagen
#   SKIP_MIGRATE=1  no ejecutar migraciones (p. ej. un cambio que es solo de variables)
#   DRY_RUN=1       no construye ni despliega: deja los YAML renderizados en un directorio temporal
# Requiere haber hecho antes los pasos de cloudrun/setup.sh (README).
set -eu
cd "$(dirname "$0")/.."
. cloudrun/config.sh
G="gcloud --project=$PROJECT"

VERSION=$(tr -d '[:space:]' < version.txt)
case "$VERSION" in
  [0-9]*.[0-9]*.[0-9]*) ;;
  *) echo "version.txt debe tener una versión semver (p. ej. 1.4.2), tiene: '$VERSION'" >&2; exit 1 ;;
esac
IMAGE="$IMAGE_REPO:$VERSION"
OUT=$(mktemp -d)

if [ "${DRY_RUN:-0}" = "1" ]; then
  INFRA_IP=${INFRA_IP:-10.0.0.0}
  SKIP=
else
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Hay cambios sin commitear: commitea primero (la imagen $VERSION debe salir de un commit)." >&2; exit 1
  fi
  SHA=$(git rev-parse --short HEAD)

  if [ "${FORCE:-0}" != "1" ] && $G artifacts docker tags list "$IMAGE_REPO" --format='value(tag)' 2>/dev/null | grep -qx "$VERSION"; then
    echo "== La imagen $IMAGE ya existe: se despliega sin reconstruir"
  else
    echo "== Construyendo $IMAGE (commit $SHA) en Cloud Build"
    $G builds submit --config cloudbuild.yaml --substitutions=_TAG="$VERSION",_SHA="$SHA" .
  fi
  [ "${BUILD_ONLY:-0}" = "1" ] && exit 0

  # IP interna de la VM (túnel al Postgres + Redis): sale de la dirección reservada, no del repo.
  INFRA_IP=$($G compute addresses describe "$INFRA_IP_NAME" --region="$REGION" --format='value(address)') || {
    echo "No existe la dirección $INFRA_IP_NAME: corre antes 'sh cloudrun/setup.sh vm'." >&2; exit 1; }

  # Secretos de los .map que aún no existen en Secret Manager: se omiten con aviso en vez de
  # dejar que Cloud Run rechace la revisión entera.
  EXISTING=$($G secrets list --format='value(name)')
  SKIP=
  for name in $(cat cloudrun/env/secrets.map cloudrun/env/secrets-reverb.map | grep -Ev '^[[:space:]]*(#|$)' | cut -d= -f2 | sort -u); do
    if ! printf '%s\n' "$EXISTING" | grep -qx "$name"; then
      SKIP="$SKIP $name"
      echo "AVISO: el secreto $name no existe en Secret Manager; su variable queda sin definir." >&2
    fi
  done
fi

echo "== Renderizando YAML en $OUT"
for t in services/api services/reverb jobs/migrate jobs/scheduler; do
  IMAGE="$IMAGE" INFRA_IP="$INFRA_IP" SKIP_SECRETS="$SKIP" sh cloudrun/render.sh "cloudrun/$t.yaml" > "$OUT/$(basename "$t").yaml"
done
if [ "${DRY_RUN:-0}" = "1" ]; then
  echo "DRY_RUN: nada desplegado. Revisa $OUT/*.yaml"; exit 0
fi

echo "== Migraciones (Job $MIGRATE_JOB)"
$G run jobs replace "$OUT/migrate.yaml" --region="$REGION" --quiet
if [ "${SKIP_MIGRATE:-0}" = "1" ]; then
  echo "SKIP_MIGRATE=1: job actualizado pero no ejecutado"
else
  $G run jobs execute "$MIGRATE_JOB" --region="$REGION" --wait
fi

echo "== Planificador (Job $SCHEDULER_JOB)"
$G run jobs replace "$OUT/scheduler.yaml" --region="$REGION" --quiet

echo "== Reverb (servicio $REVERB_SERVICE)"
$G run services replace "$OUT/reverb.yaml" --region="$REGION" --quiet

echo "== API: Octane + Horizon (servicio $API_SERVICE)"
$G run services replace "$OUT/api.yaml" --region="$REGION" --quiet

rm -rf "$OUT"
echo "== Desplegado $VERSION"
for s in "$API_SERVICE" "$REVERB_SERVICE"; do
  echo "$s: $($G run services describe "$s" --region="$REGION" --format='value(status.url)')"
done
