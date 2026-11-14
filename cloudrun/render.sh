#!/bin/sh
# Convierte una plantilla de cloudrun/services o cloudrun/jobs en el YAML final (por stdout):
#   1. `# @env archivo`      -> una entrada `- name/value` por cada CLAVE=valor de cloudrun/env/archivo
#   2. `# @secrets archivo`  -> una entrada `- name/valueFrom.secretKeyRef` por cada VARIABLE=secreto
#   3. ${VARIABLE}           -> valores de config.sh, más IMAGE e INFRA_IP que pasa deploy.sh
# Así las ~50 variables de la app se escriben una sola vez y sirven a los 4 YAML.
#
# Uso: IMAGE=... INFRA_IP=... sh cloudrun/render.sh cloudrun/services/api.yaml
# SKIP_SECRETS="A B": nombres de secretos de Secret Manager que se omiten (deploy.sh pasa los que
# todavía no existen, para que un secreto opcional vacío no tumbe el despliegue).
set -eu
DIR=$(cd "$(dirname "$0")" && pwd)
. "$DIR/config.sh"
: "${IMAGE:?falta IMAGE (imagen con tag)}"
: "${INFRA_IP:?falta INFRA_IP (IP interna de la VM de datos)}"
TEMPLATE=${1:?uso: render.sh <plantilla.yaml>}

RENDERED=$(awk -v envdir="$DIR/env" -v skip=" ${SKIP_SECRETS:-} " '
function entries(file, kind,    line, eq, key, val, path) {
    path = envdir "/" file
    while ((getline line < path) > 0) {
        if (line ~ /^[ \t]*(#|$)/) continue
        eq = index(line, "=")
        if (eq == 0) { print "render.sh: línea sin = en " path ": " line > "/dev/stderr"; exit 1 }
        key = substr(line, 1, eq - 1)
        val = substr(line, eq + 1)
        if (kind == "env") {
            gsub(/\047/, "\047\047", val)        # comilla simple de YAML: se duplica
            print indent "- name: " key
            print indent "  value: \047" val "\047"
        } else if (index(skip, " " val " ") == 0) {
            print indent "- name: " key
            print indent "  valueFrom:"
            print indent "    secretKeyRef:"
            print indent "      name: " val
            print indent "      key: latest"
        }
    }
    close(path)
}
/^[ \t]*# @(env|secrets) / {
    indent = $0; sub(/#.*/, "", indent)
    entries($3, substr($2, 2))
    next
}
{ print }
' "$TEMPLATE")
printf '%s\n' "$RENDERED" | sed \
    -e "s|\${IMAGE}|$IMAGE|g" \
    -e "s|\${INFRA_IP}|$INFRA_IP|g" \
    -e "s|\${PROJECT}|$PROJECT|g" \
    -e "s|\${REGION}|$REGION|g" \
    -e "s|\${NETWORK}|$NETWORK|g" \
    -e "s|\${RUN_SUBNET}|$RUN_SUBNET|g" \
    -e "s|\${RUN_TAG}|$RUN_TAG|g" \
    -e "s|\${RUN_SA_EMAIL}|$RUN_SA_EMAIL|g" \
    -e "s|\${API_SERVICE}|$API_SERVICE|g" \
    -e "s|\${REVERB_SERVICE}|$REVERB_SERVICE|g" \
    -e "s|\${MIGRATE_JOB}|$MIGRATE_JOB|g" \
    -e "s|\${SCHEDULER_JOB}|$SCHEDULER_JOB|g"
