#!/bin/sh
# Crea en Secret Manager los secretos de deployer-api para Cloud Run. El clúster ya no existe
# (se borró el 2026-11-11 con su Secret deployer-api-secrets), así que cada valor sale de donde
# todavía vive o se genera nuevo. Ningún valor se muestra ni se escribe en disco: va por tubería
# a gcloud.
#
#   DB_PASSWORD, webhooks de Slack   -> del .env local (son los mismos que usaba producción)
#   APP_KEY, claves de Passport      -> NUEVAS (las de producción se perdieron con el clúster):
#                                       las sesiones y tokens anteriores dejan de valer; los
#                                       usuarios vuelven a iniciar sesión
#   REVERB_APP_KEY                   -> la clave pública que ya tiene el frontend (FRONTEND_ENV);
#                                       REVERB_APP_ID y REVERB_APP_SECRET se generan nuevos
#   MAIL_USERNAME / MAIL_PASSWORD    -> ZeptoMail: usuario fijo `emailapikey`; el Send Mail Token
#                                       se pide por teclado (el .env local apunta a Mailtrap)
#   túnel chisel                     -> users.json con el AUTH que ya usa el Mac (TUNNEL_ENV) y una
#                                       clave de servidor NUEVA: cambia la huella que verifica el
#                                       cliente del Mac (el script dice cómo actualizarla)
#   clave de Redis                   -> nueva, aleatoria
#
# Es repetible: un secreto que ya existe NO se toca. Para reemplazar uno a propósito:
#   FORCE="MAIL_PASSWORD DB_PASSWORD" sh cloudrun/secrets.sh     (nombres de variable; TUNNEL y
#   REDIS para los de la VM). Rotar un valor suelto a mano: README, "Operar".
#
# Uso: sh cloudrun/secrets.sh
#      sh cloudrun/secrets.sh access   solo repite los permisos de lectura
set -eu
cd "$(dirname "$0")/.."
. cloudrun/config.sh
G="gcloud --project=$PROJECT"
DOTENV=${DOTENV:-.env}
FRONTEND_ENV=${FRONTEND_ENV:-../deployer/.env.yaml}
TUNNEL_ENV=${TUNNEL_ENV:-$HOME/.config/deployer-db-tunnel/env}
CHISEL=${CHISEL:-$HOME/.local/bin/chisel}

exists() { $G secrets describe "$1" >/dev/null 2>&1 </dev/null; }

# put <secreto>: guarda stdin como versión nueva; crea el secreto si no existe.
put() {
  exists "$1" || $G secrets create "$1" --replication-policy=automatic --quiet >/dev/null </dev/null
  $G secrets versions add "$1" --data-file=- --quiet >/dev/null
  echo "  ok      $1"
}

# secret_of <VARIABLE>: nombre del secreto según secrets.map.
secret_of() { grep -E "^$1=" cloudrun/env/secrets.map | cut -d= -f2; }

# wanted <VARIABLE> <secreto>: ¿hay que escribirlo? (no existe, o está en FORCE)
wanted() {
  case " ${FORCE:-} " in *" $1 "*) return 0 ;; esac
  if exists "$2"; then echo "  existe  $2 (no se toca)"; return 1; fi
}

# dotenv <VARIABLE>: valor del .env local, sin las comillas que lo rodeen.
dotenv() {
  grep -E "^$1=" "$DOTENV" | tail -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

from_dotenv() {
  name=$(secret_of "$1"); wanted "$1" "$name" || return 0
  value=$(dotenv "$1")
  if [ -z "$value" ] || [ "$value" = "null" ]; then
    echo "  VACÍO   $name ($1 no tiene valor en $DOTENV: se omite; deploy.sh no lo monta)"; return 0
  fi
  printf '%s' "$value" | put "$name"
}

grant() {
  $G secrets add-iam-policy-binding "$1" --member="serviceAccount:$2" \
    --role=roles/secretmanager.secretAccessor --quiet >/dev/null 2>&1 || echo "  (sin permiso: $1 no existe)"
}

if [ "${1:-}" != "access" ]; then
  echo "== del .env local ($DOTENV)"
  from_dotenv DB_PASSWORD
  from_dotenv LOG_SLACK_WEBHOOK_URL
  from_dotenv SLACK_WEBHOOK_URL

  echo "== nuevos: APP_KEY y claves de Passport"
  name=$(secret_of APP_KEY)
  if wanted APP_KEY "$name"; then
    printf 'base64:%s' "$(openssl rand -base64 32)" | put "$name"
  fi
  priv=$(secret_of PASSPORT_PRIVATE_KEY); pub=$(secret_of PASSPORT_PUBLIC_KEY)
  # Las dos van juntas: una privada nueva con la pública anterior no valida ningún token.
  if wanted PASSPORT_PRIVATE_KEY "$priv"; then
    pem=$(openssl genrsa 4096 2>/dev/null)
    printf '%s\n' "$pem" | put "$priv"
    printf '%s\n' "$pem" | openssl rsa -pubout 2>/dev/null | put "$pub"
    pem=
  fi

  echo "== Reverb"
  name=$(secret_of REVERB_APP_KEY)
  if wanted REVERB_APP_KEY "$name"; then
    key=$(grep -E '^REVERB_APP_KEY:' "$FRONTEND_ENV" | sed -e 's/^REVERB_APP_KEY:[[:space:]]*//' -e "s/^['\"]//" -e "s/['\"]\$//")
    if [ -z "$key" ]; then
      echo "  FALTA   REVERB_APP_KEY en $FRONTEND_ENV: se genera una nueva; hay que ponerla también ahí y redesplegar el frontend"
      key=$(openssl rand -hex 10)
    fi
    printf '%s' "$key" | put "$name"
  fi
  name=$(secret_of REVERB_APP_ID)
  if wanted REVERB_APP_ID "$name"; then
    od -An -N4 -tu4 /dev/urandom | tr -d ' \n' | put "$name"
  fi
  name=$(secret_of REVERB_APP_SECRET)
  if wanted REVERB_APP_SECRET "$name"; then
    openssl rand -hex 16 | tr -d '\n' | put "$name"
  fi

  echo "== correo (ZeptoMail)"
  name=$(secret_of MAIL_USERNAME)
  if wanted MAIL_USERNAME "$name"; then
    printf 'emailapikey' | put "$name"
  fi
  name=$(secret_of MAIL_PASSWORD)
  if wanted MAIL_PASSWORD "$name"; then
    if [ -t 0 ]; then
      printf '  Send Mail Token SMTP de ZeptoMail (consola > Mail Agent default > SMTP; Enter para omitir): '
      stty -echo; read -r token; stty echo; echo
    else
      token=
    fi
    if [ -n "$token" ]; then
      printf '%s' "$token" | put "$name"
    else
      echo "  OMITIDO $name: sin él los correos (código 2FA, restablecer contraseña) fallan. Repetir el script cuando lo tengas."
    fi
    token=
  fi

  echo "== túnel chisel"
  if wanted TUNNEL "$SECRET_TUNNEL_USERS"; then
    # shellcheck disable=SC1090
    AUTH=$(. "$TUNNEL_ENV" && printf '%s' "$AUTH")
    # Solo se permite el remoto inverso R:0.0.0.0:5432 (publicar el Postgres del Mac).
    AUTH="$AUTH" python3 -c 'import json,os; print(json.dumps({os.environ["AUTH"]: ["^R:0\\.0\\.0\\.0:5432$"]}))' \
      | put "$SECRET_TUNNEL_USERS"
    AUTH=
    "$CHISEL" server --keygen - 2>/dev/null | tr -d '\n' | put "$SECRET_TUNNEL_KEY"
  fi

  echo "== clave de Redis"
  if wanted REDIS "$SECRET_REDIS_PASSWORD"; then
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

# Huella de la clave del servidor chisel: el cliente del Mac la verifica con --fingerprint. Se
# calcula levantando un servidor local un instante con esa clave (no hay otra forma de derivarla).
if exists "$SECRET_TUNNEL_KEY" && [ -x "$CHISEL" ]; then
  log=$(mktemp)
  CHISEL_KEY_FILE=$($G secrets versions access latest --secret="$SECRET_TUNNEL_KEY") \
    "$CHISEL" server --host 127.0.0.1 --port 59431 >"$log" 2>&1 &
  pid=$!; sleep 1; kill "$pid" 2>/dev/null || true
  NEW=$(sed -n 's/.*Fingerprint //p' "$log" | head -1); rm -f "$log"
  CUR=$(grep -E '^FINGERPRINT=' "$TUNNEL_ENV" | cut -d= -f2-)
  if [ -z "$NEW" ]; then
    echo "No se pudo calcular la huella; leerla en la VM: sudo journalctl -u chisel-server | grep Fingerprint"
  elif [ "$NEW" = "$CUR" ]; then
    echo "Huella del túnel: el Mac ya tiene la correcta."
  else
    cat <<EOF

HUELLA NUEVA del servidor del túnel: $NEW
El cliente del Mac aún tiene la del clúster y rechazará a la VM. Actualizarla (después de crear la VM):
  sed -i '' 's|^FINGERPRINT=.*|FINGERPRINT=$NEW|' $TUNNEL_ENV
  launchctl kickstart -k gui/\$(id -u)/cl.deployer.db-tunnel
EOF
  fi
fi
echo "Listo. Ver: gcloud secrets list --project=$PROJECT --filter='name~deployer'"
