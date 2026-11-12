#!/bin/sh
# Punto de entrada del contenedor.
#
#   entrypoint octane      -> servidor HTTP (Octane + Swoole)   [por defecto]
#   entrypoint horizon     -> workers de colas
#   entrypoint scheduler   -> planificador (schedule:work)
#   entrypoint reverb      -> servidor WebSocket (Laravel Reverb)
#   entrypoint <comando>   -> cualquier otro comando, tal cual
#
# Variables que lo controlan (todas opcionales):
#   PORT / OCTANE_PORT, OCTANE_WORKERS, OCTANE_TASK_WORKERS, OCTANE_MAX_REQUESTS,
#   OCTANE_WATCH=true y HORIZON_WATCH=true (recarga al cambiar un PHP; requieren chokidar en
#   node_modules), RUN_MIGRATIONS=true,
#   REVERB_SERVER_HOST / REVERB_SERVER_PORT, REVERB_DEBUG=true (loguea cada mensaje).
set -eu

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ no existe: ejecutando composer install"
    composer install --prefer-dist --no-progress --no-interaction
fi

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/testing storage/framework/views storage/logs bootstrap/cache

# Claves de Passport: en producción deben venir por PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY
# (o estar montadas en storage/). Generarlas al vuelo invalidaría todos los tokens en cada
# despliegue o arranque en frío, por eso solo se crean automáticamente fuera de producción.
if [ -z "${PASSPORT_PRIVATE_KEY:-}" ] && [ ! -f storage/oauth-private.key ]; then
    if [ "${APP_ENV:-production}" = "production" ]; then
        echo "[entrypoint] ERROR: faltan las claves de Passport (PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY o storage/oauth-*.key)" >&2
        exit 1
    fi
    echo "[entrypoint] generando claves de Passport (solo entorno no productivo)"
    php artisan passport:keys --no-interaction
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan optimize --no-interaction
else
    php artisan optimize:clear --no-interaction
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

case "${1:-octane}" in
    octane)
        set -- php artisan octane:start \
            --server=swoole \
            --host=0.0.0.0 \
            --port="${OCTANE_PORT:-${PORT:-8000}}" \
            --workers="${OCTANE_WORKERS:-auto}" \
            --task-workers="${OCTANE_TASK_WORKERS:-auto}" \
            --max-requests="${OCTANE_MAX_REQUESTS:-500}"

        if [ "${OCTANE_WATCH:-false}" = "true" ]; then
            set -- "$@" --watch
        fi
        ;;
    horizon)
        if [ "${HORIZON_WATCH:-false}" = "true" ]; then
            set -- php artisan horizon:listen
        else
            set -- php artisan horizon
        fi
        ;;
    scheduler)
        set -- php artisan schedule:work
        ;;
    reverb)
        set -- php artisan reverb:start \
            --host="${REVERB_SERVER_HOST:-0.0.0.0}" \
            --port="${REVERB_SERVER_PORT:-6001}"

        if [ "${REVERB_DEBUG:-false}" = "true" ]; then
            set -- "$@" --debug
        fi
        ;;
esac

exec "$@"
