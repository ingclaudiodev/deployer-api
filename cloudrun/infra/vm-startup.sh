#!/bin/bash
# Script de arranque de la VM deployer-data (Ubuntu Server LTS). Corre como root en CADA arranque
# y es idempotente. Deja dos servicios de systemd:
#   postgresql     la base de datos de producción: una base y un rol superusuario (nombres por
#                  metadatos; la clave es el secreto de DB_PASSWORD). Reemplaza al Postgres del Mac
#                  que llegaba por el túnel chisel.
#   redis-server   cache, sesiones y colas de Horizon.
# Ambos escuchan en todas las interfaces: quién llega lo decide el firewall de la VPC (solo la
# subred de Cloud Run) y, además, la clave de cada uno.
# Los secretos se leen de Secret Manager con la cuenta de servicio de la VM; sus nombres y el resto
# de la configuración llegan por metadatos (setup.sh). Nada secreto queda en disco fuera de
# /etc/redis y del catálogo de Postgres (la clave del rol, como hash SCRAM).
# Reaplicar tras un cambio:  sudo google_metadata_script_runner startup
set -euo pipefail

MD=http://metadata.google.internal/computeMetadata/v1
md() { curl -fsS -H 'Metadata-Flavor: Google' "$MD/$1"; }
attr() { md "instance/attributes/$1"; }

PROJECT=$(md project/project-id)
REDIS_MAXMEMORY=$(attr redis-maxmemory)
PG_VERSION=$(attr postgres-version)
PG_SHARED_BUFFERS=$(attr postgres-shared-buffers)
DB_NAME=$(attr db-name)
DB_USER=$(attr db-user)
DB_ALLOWED_CIDR=$(attr db-allowed-cidr)

# Lee la versión `latest` de un secreto por la API REST (sin depender de gcloud en la imagen).
secret() {
    local token
    token=$(md instance/service-accounts/default/token | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')
    curl -fsS -H "Authorization: Bearer $token" \
        "https://secretmanager.googleapis.com/v1/projects/$PROJECT/secrets/$1/versions/latest:access" \
        | python3 -c 'import base64,json,sys; sys.stdout.buffer.write(base64.b64decode(json.load(sys.stdin)["payload"]["data"]))'
}

if ! command -v redis-server >/dev/null || [ ! -d "/etc/postgresql/$PG_VERSION/main" ]; then
    # En el primer arranque unattended-upgrades suele tener el candado de apt: se espera en vez de fallar.
    apt-get -o DPkg::Lock::Timeout=300 update -q || { sleep 30; apt-get -o DPkg::Lock::Timeout=300 update -q; }
    DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -q redis-server "postgresql-$PG_VERSION"
fi

# ---------------------------------------------------------------- Redis
REDIS_PASSWORD=$(secret "$(attr secret-redis-password)")
# volatile-lru: cache y sesiones (con TTL) se desalojan antes que las colas.
cat > /etc/redis/deployer.conf <<EOF
bind 0.0.0.0
protected-mode yes
requirepass $REDIS_PASSWORD
maxmemory $REDIS_MAXMEMORY
maxmemory-policy volatile-lru
appendonly yes
appendfsync everysec
EOF
chown redis:redis /etc/redis/deployer.conf
chmod 640 /etc/redis/deployer.conf
grep -q '^include /etc/redis/deployer.conf' /etc/redis/redis.conf \
    || echo 'include /etc/redis/deployer.conf' >> /etc/redis/redis.conf
systemctl enable redis-server
systemctl restart redis-server

# ---------------------------------------------------------------- PostgreSQL
PG_ETC=/etc/postgresql/$PG_VERSION/main
cat > "$PG_ETC/conf.d/deployer.conf" <<EOF
listen_addresses = '*'
password_encryption = scram-sha-256
shared_buffers = $PG_SHARED_BUFFERS
EOF
# pg_hba: por red solo entra el rol de la app, a su base, desde la subred de Cloud Run y con clave.
# El bloque se reescribe entero en cada arranque (los nombres pueden cambiar por metadatos).
sed -i '/^# deployer: inicio$/,/^# deployer: fin$/d' "$PG_ETC/pg_hba.conf"
cat >> "$PG_ETC/pg_hba.conf" <<EOF
# deployer: inicio
host    "$DB_NAME"    "$DB_USER"    $DB_ALLOWED_CIDR    scram-sha-256
# deployer: fin
EOF
systemctl enable postgresql
systemctl restart "postgresql@$PG_VERSION-main"
# El reinicio puede volver antes de que el servidor acepte conexiones (recuperación tras un apagado brusco).
for _ in $(seq 30); do pg_isready -q && break; sleep 2; done
pg_isready -q

# Rol y base. La clave se reaplica en cada arranque: rotar el secreto y reiniciar basta para
# cambiarla. Los valores viajan por entorno (\getenv), no por argumentos ni interpolados en el SQL.
# Misma codificación y orden que la base de origen (UTF8 / C), para que el volcado entre igual.
export DEPLOYER_DB_NAME=$DB_NAME DEPLOYER_DB_USER=$DB_USER
DEPLOYER_DB_PASSWORD=$(secret "$(attr secret-db-password)")
export DEPLOYER_DB_PASSWORD
cd /
runuser -u postgres -- psql -v ON_ERROR_STOP=1 -q -d postgres <<'SQL'
\getenv usr DEPLOYER_DB_USER
\getenv db DEPLOYER_DB_NAME
\getenv pw DEPLOYER_DB_PASSWORD
SELECT format('CREATE ROLE %I LOGIN SUPERUSER', :'usr')
 WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'usr') \gexec
ALTER ROLE :"usr" LOGIN SUPERUSER PASSWORD :'pw';
SELECT format('CREATE DATABASE %I OWNER %I TEMPLATE template0 ENCODING ''UTF8'' LOCALE ''C''', :'db', :'usr')
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'db') \gexec
SQL
unset DEPLOYER_DB_PASSWORD
echo "deployer-data: PostgreSQL $PG_VERSION (base $DB_NAME, rol $DB_USER) y Redis listos"
