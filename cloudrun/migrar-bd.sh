#!/bin/sh
# Traspaso ÚNICO de la base de datos: vuelca el Postgres local (el del .env) completo, esquema y
# datos, y lo restaura en la base de la VM de datos (setup.sh vm), que debe estar vacía.
#
#   - En el Mac solo LEE (pg_dump). La base local queda intacta y pasa a ser solo de desarrollo.
#   - En la VM ESCRIBE: crea todas las tablas, índices y secuencias y carga todas las filas, en una
#     sola transacción (si algo falla, la base de la VM queda vacía como estaba).
#   - Se niega a restaurar sobre una base que ya tenga tablas: no pisa datos de producción.
#   - El volcado (lleva hashes de contraseñas y tokens) vive en un directorio temporal con permisos
#     solo para ti y se borra al terminar; viaja por SSH a través de IAP, nunca por Internet abierto.
#
# Lo que se escriba en la base local después de este volcado NO llega a producción: hacerlo justo
# antes de `sh cloudrun/deploy.sh` (README, "Traspaso desde deployer-infra").
#
# Uso: sh cloudrun/migrar-bd.sh
#   PG_BIN=/ruta/bin   dónde están pg_dump y psql (versión no menor que la del servidor local)
#   DOTENV=.env        de dónde salen DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD
set -eu
cd "$(dirname "$0")/.."
. cloudrun/config.sh
G="gcloud --project=$PROJECT"
DOTENV=${DOTENV:-.env}
PG_BIN=${PG_BIN:-/Library/PostgreSQL/$POSTGRES_VERSION/bin}

# dotenv <VARIABLE>: valor del .env local, sin las comillas que lo rodeen.
dotenv() {
  grep -E "^$1=" "$DOTENV" | tail -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}
PGHOST=$(dotenv DB_HOST); PGPORT=$(dotenv DB_PORT); PGDATABASE=$(dotenv DB_DATABASE)
PGUSER=$(dotenv DB_USERNAME); PGPASSWORD=$(dotenv DB_PASSWORD)
export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD
[ -x "$PG_BIN/pg_dump" ] || { echo "No está $PG_BIN/pg_dump: indica la carpeta con PG_BIN=..." >&2; exit 1; }

# remote <comando>: lo ejecuta en la VM (stdin se pasa tal cual).
remote() { $G compute ssh "$VM_NAME" --zone="$ZONE" --tunnel-through-iap --command="$1"; }
REMOTE_PSQL="cd / && sudo -u postgres psql -v ON_ERROR_STOP=1 -At -d '$DB_NAME'"

umask 077
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT INT TERM
# Filas exactas por tabla, para comparar origen y destino. Va por stdin: sin comillas que escapar.
cat > "$TMP/filas.sql" <<'SQL'
SELECT format('SELECT %L, count(*) FROM %I.%I', schemaname || '.' || relname, schemaname, relname)
  FROM pg_stat_user_tables ORDER BY 1 \gexec
SQL

echo "== Origen: $PGDATABASE en $PGHOST:$PGPORT (usuario $PGUSER)"
"$PG_BIN/psql" -v ON_ERROR_STOP=1 -At -F ' ' < "$TMP/filas.sql" > "$TMP/origen.txt"
TABLES=$(wc -l < "$TMP/origen.txt" | tr -d ' ')
ROWS=$(awk '{n += $2} END {print n + 0}' "$TMP/origen.txt")
[ "$TABLES" -gt 0 ] || { echo "La base de origen no tiene tablas: nada que traspasar." >&2; exit 1; }

echo "== Destino: base $DB_NAME de la VM $VM_NAME"
EXISTING=$(echo 'SELECT count(*) FROM pg_stat_user_tables' | remote "$REMOTE_PSQL" | tr -d '[:space:]')
case "$EXISTING" in
  0) ;;
  ''|*[!0-9]*) echo "No se pudo consultar la base de la VM (¿terminó su arranque? setup.sh vm dice cómo ver el log)." >&2; exit 1 ;;
  *) echo "La base $DB_NAME de la VM ya tiene $EXISTING tablas: no se restaura encima. Nada cambió." >&2; exit 1 ;;
esac

cat <<EOF

Se va a restaurar en la VM $VM_NAME, base $DB_NAME (hoy vacía):
  $TABLES tablas con $ROWS filas en total, con sus índices y secuencias, como propiedad de $DB_SUPERUSER.
La base local no se modifica.
EOF
printf 'Escribe "restaurar" para continuar: '
read -r answer
[ "$answer" = "restaurar" ] || { echo "Cancelado: nada cambió."; exit 1; }

echo "== Volcando $PGDATABASE"
"$PG_BIN/pg_dump" --format=custom --no-owner --no-privileges --file="$TMP/base.dump"

echo "== Restaurando en $VM_NAME"
remote "cd / && sudo -u postgres pg_restore --dbname='$DB_NAME' --single-transaction --exit-on-error --no-owner --role='$DB_SUPERUSER'" < "$TMP/base.dump"
echo 'ANALYZE' | remote "$REMOTE_PSQL" >/dev/null

echo "== Comparando filas por tabla"
remote "$REMOTE_PSQL -F ' '" < "$TMP/filas.sql" > "$TMP/destino.txt"
if diff "$TMP/origen.txt" "$TMP/destino.txt" >/dev/null; then
  echo "Traspaso correcto: $TABLES tablas, $ROWS filas, idénticas en origen y destino."
  echo "Sigue: sh cloudrun/deploy.sh (apunta la API a la VM) y probar un inicio de sesión."
else
  echo "DIFERENCIAS entre origen (<) y destino (>):" >&2
  diff "$TMP/origen.txt" "$TMP/destino.txt" >&2 || true
  exit 1
fi
