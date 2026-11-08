#!/bin/sh
# Despliegue versionado de deployer-api en deployer-cluster. La versión de la imagen es la de
# version.txt (semver), nunca :latest ni el sha a secas. Flujo:
#   1. lee version.txt y el sha corto; exige árbol commiteado (la imagen debe corresponder a un commit)
#   2. se niega a reconstruir una versión que ya existe en Artifact Registry (tags inmutables);
#      para republicar la misma versión a propósito: FORCE=1 sh k8s/deploy.sh
#   3. Cloud Build sube :<version> y :<version>-<sha> (cloudbuild.yaml)
#   4. fija el tag en k8s/kustomization.yaml, corre el Job de migraciones y aplica k8s/
#   5. commitea k8s/kustomization.yaml ("deploy <version>")
# Uso (desde la raíz del repo): sh k8s/deploy.sh          Solo construir: BUILD_ONLY=1 sh k8s/deploy.sh
set -eu
cd "$(dirname "$0")/.."
PROJECT=${PROJECT:-claudio-orellana-services}
IMAGE=us-central1-docker.pkg.dev/$PROJECT/deployer/deployer-api
NS=deployer-api

VERSION=$(tr -d '[:space:]' < version.txt)
case "$VERSION" in
  [0-9]*.[0-9]*.[0-9]*) ;;
  *) echo "version.txt debe tener una versión semver (p. ej. 1.4.2), tiene: '$VERSION'" >&2; exit 1 ;;
esac
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Hay cambios sin commitear: commitea primero (la imagen $VERSION debe salir de un commit)." >&2; exit 1
fi
SHA=$(git rev-parse --short HEAD)

if [ "${FORCE:-0}" != "1" ] && gcloud artifacts docker tags list "$IMAGE" --project="$PROJECT" --format='value(tag)' 2>/dev/null | grep -qx "$VERSION"; then
  echo "La versión $VERSION ya existe en Artifact Registry. Sube version.txt (y commitea) o usa FORCE=1." >&2; exit 1
fi

echo "== Construyendo $IMAGE:$VERSION (commit $SHA) en Cloud Build"
gcloud builds submit --config cloudbuild.yaml --substitutions=_TAG="$VERSION",_SHA="$SHA" --project="$PROJECT" .
[ "${BUILD_ONLY:-0}" = "1" ] && exit 0

echo "== Fijando tag $VERSION en k8s/kustomization.yaml"
if command -v kustomize >/dev/null; then
  (cd k8s && kustomize edit set image "$IMAGE=:$VERSION")
else
  sed -i.bak "s/^\(    newTag: \).*/\1$VERSION/" k8s/kustomization.yaml && rm -f k8s/kustomization.yaml.bak
fi

echo "== Migraciones"
kubectl -n "$NS" delete job migrate --ignore-not-found
sed "s/:latest/:$VERSION/" k8s/migrate/job.yaml | kubectl apply -f -
kubectl -n "$NS" wait --for=condition=complete job/migrate --timeout=300s
kubectl -n "$NS" logs job/migrate | tail -20

echo "== Aplicando k8s/ (Rollout blue/green de app; Recreate de horizon y scheduler; rolling de reverb)"
kubectl apply -k k8s
git add k8s/kustomization.yaml && git commit -q -m "deploy $VERSION" && echo "== Commit: deploy $VERSION ($SHA)"
