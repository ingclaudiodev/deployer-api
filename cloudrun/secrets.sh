#!/bin/sh
# Copia los secretos de Kubernetes a Secret Manager, sin mostrarlos ni escribirlos en disco: cada
# valor va por tubería de kubectl a gcloud. Hay que correrlo MIENTRAS EL CLÚSTER EXISTA.
#   Secret deployer-api-secrets  -> un secreto por variable (nombres en cloudrun/env/secrets.map)
#   Secret db-tunnel-auth        -> users.json y key del servidor chisel (los lee la VM)
#   clave de Redis               -> nueva, aleatoria (el Redis del clúster no tenía)
# Repetirlo agrega una versión nueva a cada secreto (así se rota un valor; ver README).
# Un secreto vacío en el clúster se omite con aviso: deploy.sh no lo monta.
#
# El contexto de kubectl va FIJO (KUBE_CONTEXT): este Mac tiene también el clúster de otro proyecto
# y el contexto activo puede ser ese. Antes de copiar muestra qué claves hay en el clúster y cuáles
# no están en secrets.map (el clúster ya divergió del repo alguna vez).
#
# Uso: sh cloudrun/secrets.sh
#      sh cloudrun/secrets.sh access   solo repite los permisos de lectura
set -eu
cd "$(dirname "$0")/.."
. cloudrun/config.sh
G="gcloud --project=$PROJECT"
NS=${NS:-deployer-api}
KUBE_CONTEXT=${KUBE_CONTEXT:-gke_${PROJECT}_${REGION}_deployer-cluster}
K="kubectl --context=$KUBE_CONTEXT -n $NS"

# put <secreto>: guarda stdin como versión nueva; crea el secreto si no existe.
put() {
  if ! $G secrets describe "$1" >/dev/null 2>&1 </dev/null; then
    $G secrets create "$1" --replication-policy=automatic --quiet >/dev/null </dev/null
  fi
  $G secrets versions add "$1" --data-file=- --quiet >/dev/null
  echo "  ok     $1"
}

# from_k8s <secret-k8s> <clave> <secreto>: copia una clave; la omite si está vacía.
from_k8s() {
  key=$(printf '%s' "$2" | sed 's/\./\\./g')
  if [ -z "$($K get secret "$1" -o "jsonpath={.data.$key}" </dev/null)" ]; then
    echo "  VACÍO  $3 ($1/$2 no tiene valor en el clúster: se omite)"
    return 0
  fi
  $K get secret "$1" -o "jsonpath={.data.$key}" </dev/null | base64 -d | put "$3"
}

grant() {
  $G secrets add-iam-policy-binding "$1" --member="serviceAccount:$2" \
    --role=roles/secretmanager.secretAccessor --quiet >/dev/null 2>&1 || echo "  (sin permiso: $1 no existe)"
}

if [ "${1:-}" != "access" ]; then
  echo "== claves de deployer-api-secrets en $KUBE_CONTEXT que NO están en cloudrun/env/secrets.map"
  $K get secret deployer-api-secrets -o go-template='{{range $k, $v := .data}}{{$k}}{{"\n"}}{{end}}' \
    | while read -r k; do grep -q "^$k=" cloudrun/env/secrets.map || echo "  FALTA  $k (agregarla a secrets.map si la app la usa)"; done

  echo "== deployer-api-secrets -> Secret Manager"
  grep -Ev '^[[:space:]]*(#|$)' cloudrun/env/secrets.map | while IFS='=' read -r var name; do
    [ "$name" = "$SECRET_REDIS_PASSWORD" ] && continue
    from_k8s deployer-api-secrets "$var" "$name"
  done

  echo "== db-tunnel-auth -> Secret Manager"
  from_k8s db-tunnel-auth users.json "$SECRET_TUNNEL_USERS"
  from_k8s db-tunnel-auth key "$SECRET_TUNNEL_KEY"

  echo "== clave de Redis"
  if $G secrets describe "$SECRET_REDIS_PASSWORD" >/dev/null 2>&1; then
    echo "  existe $SECRET_REDIS_PASSWORD (no se regenera)"
  else
    openssl rand -hex 24 | tr -d '\n' | put "$SECRET_REDIS_PASSWORD"
  fi
fi

echo "== permisos de lectura"
# Cloud Run: cada secreto de los .map. La VM: solo los tres suyos.
cat cloudrun/env/secrets.map cloudrun/env/secrets-reverb.map | grep -Ev '^[[:space:]]*(#|$)' \
  | cut -d= -f2 | sort -u | while read -r name; do grant "$name" "$RUN_SA_EMAIL"; done
for name in "$SECRET_TUNNEL_USERS" "$SECRET_TUNNEL_KEY" "$SECRET_REDIS_PASSWORD"; do
  grant "$name" "$VM_SA_EMAIL"
done
echo "Listo. Ver: gcloud secrets list --project=$PROJECT --filter='name~deployer'"
