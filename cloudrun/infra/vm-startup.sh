#!/bin/bash
# Script de arranque de la VM deployer-infra (Debian 12, e2-micro). Corre como root en CADA
# arranque y es idempotente. Deja dos servicios de systemd:
#   chisel-server  servidor del túnel inverso al Postgres del Mac. Escucha en 443 con certificado
#                  de Let's Encrypt (--tls-domain) y, cuando el Mac se conecta, abre el 5432.
#                  Reemplaza al Deployment db-tunnel + HTTPRoute + certificado del Gateway.
#   redis-server   cache, sesiones y colas de Horizon. Reemplaza al Redis compartido del clúster.
# Los secretos (usuarios y clave de chisel, clave de Redis) se leen de Secret Manager con la cuenta
# de servicio de la VM; sus nombres y el dominio llegan por metadatos (setup.sh). Nada va en disco
# fuera de /etc/chisel y /etc/redis.
# Reaplicar tras un cambio:  sudo google_metadata_script_runner startup
set -euo pipefail

CHISEL_VERSION=1.13.0   # la misma del cliente del Mac (~/.local/bin/chisel)
CHISEL_SHA256=902a38e824bfb4d4e428faa45607a9b71fc74ff28bf1392cf8121650061c6971   # chisel_1.13.0_linux_amd64.gz

MD=http://metadata.google.internal/computeMetadata/v1
md() { curl -fsS -H 'Metadata-Flavor: Google' "$MD/$1"; }
attr() { md "instance/attributes/$1"; }

PROJECT=$(md project/project-id)
TUNNEL_DOMAIN=$(attr tunnel-domain)
REDIS_MAXMEMORY=$(attr redis-maxmemory)

# Lee la versión `latest` de un secreto por la API REST (sin depender de gcloud en la imagen).
secret() {
    local token
    token=$(md instance/service-accounts/default/token | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')
    curl -fsS -H "Authorization: Bearer $token" \
        "https://secretmanager.googleapis.com/v1/projects/$PROJECT/secrets/$1/versions/latest:access" \
        | python3 -c 'import base64,json,sys; sys.stdout.buffer.write(base64.b64decode(json.load(sys.stdin)["payload"]["data"]))'
}

# ---------------------------------------------------------------- Redis
if ! command -v redis-server >/dev/null; then
    apt-get update -q
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q redis-server
fi
REDIS_PASSWORD=$(secret "$(attr secret-redis-password)")
# volatile-lru: cache y sesiones (con TTL) se desalojan antes que las colas, como en el clúster.
# bind 0.0.0.0: quién llega lo decide el firewall de la VPC (solo las instancias de Cloud Run).
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

# ---------------------------------------------------------------- chisel
if [ "$(/usr/local/bin/chisel --version 2>/dev/null || true)" != "$CHISEL_VERSION" ]; then
    tmp=$(mktemp -d)
    curl -fsSL -o "$tmp/chisel.gz" \
        "https://github.com/jpillora/chisel/releases/download/v$CHISEL_VERSION/chisel_${CHISEL_VERSION}_linux_amd64.gz"
    echo "$CHISEL_SHA256  $tmp/chisel.gz" | sha256sum -c -
    gunzip "$tmp/chisel.gz"
    install -m 0755 "$tmp/chisel" /usr/local/bin/chisel
    rm -rf "$tmp"
fi
id chisel >/dev/null 2>&1 || useradd --system --home-dir /var/lib/chisel --shell /usr/sbin/nologin chisel
install -d -o chisel -g chisel -m 0700 /etc/chisel /var/lib/chisel
# users.json: el usuario del Mac, limitado al remoto inverso R:0.0.0.0:5432. key: clave fija del
# servidor => la huella que el cliente del Mac verifica (--fingerprint) no cambia.
secret "$(attr secret-tunnel-users)" > /etc/chisel/users.json
secret "$(attr secret-tunnel-key)" > /etc/chisel/key
chown chisel:chisel /etc/chisel/users.json /etc/chisel/key
chmod 600 /etc/chisel/users.json /etc/chisel/key

# CAP_NET_BIND_SERVICE: escuchar en 443 sin ser root. CHISEL_LE_CACHE: el certificado de Let's
# Encrypt sobrevive a los reinicios (sin esto se pediría uno nuevo cada vez, y hay cuota).
cat > /etc/systemd/system/chisel-server.service <<EOF
[Unit]
Description=chisel server: tunel inverso al Postgres del Mac (deployer-api)
After=network-online.target
Wants=network-online.target

[Service]
User=chisel
Group=chisel
Environment=CHISEL_KEY_FILE=/etc/chisel/key
Environment=CHISEL_LE_CACHE=/var/lib/chisel/letsencrypt
ExecStart=/usr/local/bin/chisel server --reverse --port 443 --tls-domain $TUNNEL_DOMAIN --keepalive 25s --authfile /etc/chisel/users.json
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/var/lib/chisel
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable chisel-server
systemctl restart chisel-server
