# Cloud Run

Despliegue de deployer-api en Cloud Run, en reemplazo del clúster GKE (`k8s/`). La imagen es la
misma (`docker/Dockerfile`, target `app`): ya escuchaba en `$PORT`, corre sin root y se construye
en amd64 con Cloud Build. Lo que cambia es quién hace lo que antes hacía el clúster.

> Estado: archivos listos, **nada aplicado todavía**. `k8s/` sigue intacto y es el rollback.

## Qué reemplaza a qué

| En Kubernetes | En Cloud Run | Archivo |
|---|---|---|
| Rollout `app` + KEDA HTTP Add-on (0-2 pods) | Servicio `deployer-api`, contenedor `app` (0-2 instancias) | `services/api.yaml` |
| Deployment `horizon` (1 pod fijo) | Contenedor `horizon`, **sidecar** del mismo servicio | `services/api.yaml` |
| Deployment `reverb` + HTTPRoute `ws.deployer.cl` | Servicio `deployer-reverb` (0-1 instancias) | `services/reverb.yaml` |
| Deployment `scheduler` (`schedule:work`, 1 pod fijo) | Cloud Scheduler → Job `deployer-scheduler` (`schedule:run`) | `jobs/scheduler.yaml` |
| Job `migrate` | Job `deployer-migrate` (lo ejecuta `deploy.sh`) | `jobs/migrate.yaml` |
| ConfigMap `deployer-api-env` / `reverb-config` | `env/common.env` / `env/reverb.env` | `render.sh` los vuelca al YAML |
| Secret `deployer-api-secrets` | Secret Manager, un secreto por variable | `env/secrets.map`, `secrets.sh` |
| Deployment `db-tunnel` (servidor chisel) + Gateway | VM `deployer-infra`: chisel en 443 con Let's Encrypt | `infra/vm-startup.sh` |
| Redis compartido del clúster | Redis en la misma VM, con clave | `infra/vm-startup.sh` |
| HAProxy (CORS) | Laravel (`config/cors.php`, origen = `FRONTEND_URL`) | — |
| Gateway + certificado wildcard | Asignación de dominio de Cloud Run (certificado administrado) | `setup.sh domains` |

```
navegador ── https://backend.deployer.cl ──▶ Cloud Run deployer-api ─┐  [app: Octane] [horizon]
navegador ── wss://ws.deployer.cl ────────▶ Cloud Run deployer-reverb │
Cloud Scheduler ─ cada 5 min ─▶ Job deployer-scheduler ───────────────┤ Direct VPC egress
deploy.sh ─────────────────────▶ Job deployer-migrate ────────────────┤ (red privada)
                                                                      ▼
                                  VM deployer-infra (e2-micro):  Redis :6379
                                                                 chisel :5432 ◀═ túnel ═ Mac (Postgres)
                                                                 chisel :443  ◀─ wss://db-tunnel.deployer.cl
```

## Decisiones que conviene entender

- **La base de datos sigue siendo el Postgres del Mac.** Lo que quedó expuesto a Internet es solo
  el WebSocket de chisel; el puerto 5432 vivía *dentro* del clúster. Sin clúster, el servidor chisel
  necesita otra casa con IP pública: la VM. El Mac no cambia nada (misma URL, mismo usuario, misma
  huella): basta apuntar `db-tunnel.deployer.cl` a la VM. Siguen valiendo los ~180 ms por consulta,
  el "connection refused" si el Mac duerme y que es la MISMA base del desarrollo.
- **Horizon es un sidecar, no un servicio siempre encendido.** Todos los jobs nacen de una petición
  HTTP (código 2FA, restablecer contraseña, bienvenida por WebSocket, alertas), así que basta que
  Horizon viva mientras vive la instancia de la API. Para que tenga CPU después de que Octane
  responde, el servicio usa facturación por instancia (`cpu-throttling: false`): se cobra desde la
  primera petición hasta que Cloud Run apaga la instancia inactiva, y nada cuando está en cero.
  Esos 15 minutos son un TOPE, no una garantía: Cloud Run puede apagarla antes. Si lo hiciera con
  un job aún en cola (p. ej. el correo del código 2FA), el job no se pierde: queda en Redis y sale
  con la siguiente petición que despierte una instancia. Si eso se llegara a ver en la práctica,
  la salida es `minScale: '1'` (una instancia siempre viva, ~US$80 al mes) o un worker pool.
  Límite: un job con retraso largo (`->delay()` de más de ~15 min) esperaría a la próxima visita.
  Hoy el mayor es de 3 s. Si aparece uno, Horizon debe pasar a un worker pool de Cloud Run.
- **El planificador es un Job, no una llamada a la API**: un ping cada 5 minutos mantendría la API
  despierta todo el día. `horizon:snapshot` solo alimenta los gráficos del panel de Horizon; para
  ahorrar ejecuciones se puede poner `SCHEDULE='0 * * * *'` (cada hora) al correr `setup.sh scheduler`.
- **Reverb**: un WebSocket abierto cuenta como petición en curso, así que la instancia se cobra
  mientras haya una pestaña del panel abierta. Cloud Run corta cada socket a los 60 minutos y el
  cliente reconecta solo. Máximo 1 instancia (las conexiones viven en memoria).
- **Apagado**: Cloud Run da 10 s tras SIGTERM (Kubernetes daba 60-90 s). Un job de Horizon en
  curso al apagar la instancia se corta; con `tries: 1` (config/horizon.php) no se reintenta.

## Costo esperado

Valores aproximados de us-central1; confirmar en <https://cloud.google.com/run/pricing>.

| Pieza | Cuándo cobra | Orden de magnitud |
|---|---|---|
| `deployer-api` (1,5 vCPU + 1,5 GiB) | solo con instancias vivas | ~US$0,11 por hora activa; la capa gratuita cubre unas 40 h al mes |
| `deployer-reverb` (1 vCPU + 512 MiB) | solo con sockets abiertos | ~US$0,09 por hora con alguien conectado |
| Jobs (scheduler cada 5 min, migrate) | segundos por ejecución | centavos al día |
| VM `e2-micro` + disco estándar 10 GB | siempre | US$0 (capa gratuita de Compute Engine en us-central1) |
| IP pública de la VM | siempre | ~US$3,65 al mes (no la cubre la capa gratuita) |
| Secret Manager, Cloud Scheduler, Artifact Registry | — | dentro de sus capas gratuitas a este volumen |

Sin tráfico el total es la IP de la VM más los jobs del planificador.

## Primera vez

Requisitos: `gcloud` con una cuenta que administre `claudio-orellana-services`
(`gcloud config set account <cuenta>`: la cuenta activa por defecto de este Mac es de otro proyecto
y recibe 403) y acceso con `kubectl` a `deployer-cluster` para copiar los secretos. `secrets.sh`
usa siempre el contexto `gke_claudio-orellana-services_us-central1_deployer-cluster`, sin importar
cuál esté activo.

Antes de empezar, comparar la configuración VIVA con `env/common.env`: se copió del ConfigMap del
repo (`k8s/base/configmap.yaml`) y el clúster ya divergió del repo alguna vez.

```sh
kubectl --context=gke_claudio-orellana-services_us-central1_deployer-cluster -n deployer-api \
  get cm deployer-api-env -o go-template='{{range $k, $v := .data}}{{$k}}={{$v}}{{"\n"}}{{end}}' | sort > /tmp/vivo.env
grep -Ev '^\s*(#|$)' cloudrun/env/common.env | sort | diff /tmp/vivo.env -
# Diferencias esperadas: DB_HOST, REDIS_HOST, REVERB_HOST/PORT/SCHEME, TRUSTED_PROXIES, HORIZON_MAX_PROCESSES.
```

```sh
# 0. Subir la versión (config/cors.php y HORIZON_MAX_PROCESSES son código nuevo) y commitear
#    (no usar `git add -A` si hay otros cambios pendientes que no son de esto, p. ej. en k8s/)
echo 0.1.8 > version.txt
git add version.txt cloudrun config/cors.php config/horizon.php .env.example tests/Feature/CorsTest.php
git commit -m "version 0.1.8 (Cloud Run)"

sh cloudrun/setup.sh apis        # 1. APIs
sh cloudrun/setup.sh iam         # 2. cuentas de servicio
sh cloudrun/secrets.sh           # 3. secretos: clúster -> Secret Manager (con el clúster aún vivo)
sh cloudrun/setup.sh vm          # 4. VM deployer-infra: IPs, firewall, chisel y Redis
DRY_RUN=1 sh cloudrun/deploy.sh  # 5. opcional: ver los YAML finales sin desplegar nada
sh cloudrun/deploy.sh            # 6. construye, migra y crea servicios y jobs
sh cloudrun/setup.sh public      # 7. abre la API y Reverb a Internet (antes responden 403)
sh cloudrun/setup.sh scheduler   # 8. Cloud Scheduler -> Job del planificador
```

Hasta el cutover, Cloud Run y el clúster conviven. Dos cosas a tener presentes en esa ventana:

- El paso 6 ejecuta `migrate` contra la base **solo si el túnel ya llega a la VM**. Antes del
  cutover del túnel la VM no tiene el 5432 abierto y el job falla con "connection refused": correr
  el primer despliegue con `SKIP_MIGRATE=1` (la base ya está migrada por el clúster) o hacer antes
  el paso 1 del cutover.
- El túnel es **uno solo**: mientras `db-tunnel.deployer.cl` apunte al clúster, la API de Cloud Run
  arranca y responde `/up`, pero cualquier ruta que toque la base falla. La API de Cloud Run solo
  se puede probar de verdad después de mover el túnel, y en ese momento el clúster pierde la base.
  Para probar SIN cortar al clúster: crear la VM con otro host
  (`TUNNEL_DOMAIN=db-tunnel2.deployer.cl sh cloudrun/setup.sh vm`, más su registro A) y lanzar en el
  Mac un segundo cliente chisel contra ese host; los dos túneles publican el mismo Postgres.

## Cutover

`sh cloudrun/setup.sh domains` imprime los comandos exactos con las IP reales. Orden:

1. **Túnel**: registro A de `db-tunnel.deployer.cl` → IP pública de la VM. El cliente del Mac
   reconecta solo (reintenta cada 15 s) en cuanto expira el TTL; chisel pide su certificado a Let's
   Encrypt en la primera conexión. Desde aquí la API del clúster ya no tiene base de datos.
   Comprobar: `tail ~/Library/Logs/deployer-db-tunnel.log` (`Connected (Latency ...)`).
2. **Probar Cloud Run por su URL `*.run.app`** (sección Verificar) antes de tocar los dominios.
3. **Dominios**: asignar `backend.deployer.cl` y `ws.deployer.cl` a los servicios y cambiar sus
   registros a `CNAME ghs.googlehosted.com.`. El certificado tarda unos 15 minutos; en ese lapso el
   dominio responde con error de TLS, así que conviene hacerlo en horario sin uso.
4. **Apagar el clúster** solo cuando lo anterior lleve un día funcionando. Borrar el clúster es
   irreversible y elimina también HAProxy, KEDA, Redis y el Gateway (`../cluster-deployer`). Las
   rutas del Gateway hoy son solo estos tres hosts (`cluster-deployer/gateway/routes/`: backend,
   reverb, db-tunnel y la redirección a HTTPS); confirmar con `kubectl get httproute -A` que no
   haya otra antes de borrar. Quedan además por limpiar el Cloud NAT `deployer-nat`, la IP del
   Gateway y los recursos PSC huérfanos de la antigua Cloud SQL.

La asignación de dominio de Cloud Run es gratuita pero Google la mantiene en *preview* (puede sumar
latencia). La alternativa soportada es un balanceador HTTPS externo delante de los servicios,
que cuesta del orden de US$18 al mes: no vale la pena mientras el servicio esté en desarrollo.

Rollback: devolver los tres registros DNS a sus valores anteriores (anotarlos antes) y el clúster
vuelve a servir todo. Por eso `k8s/` no se toca.

## Cada despliegue

```sh
echo 1.4.2 > version.txt && git add -A && git commit -m "v1.4.2"
sh cloudrun/deploy.sh      # BUILD_ONLY=1 · SKIP_MIGRATE=1 · DRY_RUN=1 · FORCE=1 (reconstruir la versión)
```

Si la versión ya existe en Artifact Registry no se reconstruye: se despliega tal cual. Sirve para
aplicar un cambio de variables (`env/*.env`) sin subir la versión.

## Verificar

```sh
P=claudio-orellana-services; R=us-central1
API=$(gcloud run services describe deployer-api --project=$P --region=$R --format='value(status.url)')
curl -s -o /dev/null -w '%{http_code} %{time_total}s\n' $API/up     # 200 (la primera tarda: arranque en frío)

# CORS: debe devolver access-control-allow-origin: https://deployer.cl
curl -si -X OPTIONS $API/api/auth/login -H 'Origin: https://deployer.cl' \
  -H 'Access-Control-Request-Method: POST' | grep -i access-control

# Base de datos y Redis: un login real desde el frontend, o mirar los logs de ambos contenedores
gcloud run services logs read deployer-api --project=$P --region=$R --limit=50
# Horizon vivo: en los logs del contenedor horizon aparece "Horizon started successfully."

# Planificador y migraciones
gcloud run jobs executions list --project=$P --region=$R --job=deployer-scheduler --limit=5

# VM: túnel y Redis
gcloud compute ssh deployer-infra --project=$P --zone=us-central1-a --tunnel-through-iap \
  --command='systemctl --no-pager status chisel-server redis-server | grep -E "●|Active"; sudo journalctl -u chisel-server -n 5 --no-pager'
```

Tras el primer login por el dominio definitivo, confirmar que el throttle ve la IP real del
cliente y no la de un proxy de Google: si los logs muestran siempre la misma IP, ajustar
`TRUSTED_PROXIES` en `env/common.env` con el rango que aparezca en `X-Forwarded-For`.

## Operar

- **Cambiar una variable**: editar `env/common.env` (o `reverb.env`), commitear y `sh cloudrun/deploy.sh`.
- **Rotar un secreto**: `printf '%s' 'valor' | gcloud secrets versions add <nombre> --project=... --data-file=-`
  y volver a desplegar (las instancias leen `latest` al arrancar). Nombres en `env/secrets.map`.
- **Credenciales del túnel**: nueva versión de `deployer-db-tunnel-users` / `deployer-db-tunnel-key`,
  reaplicar el arranque de la VM (`sudo google_metadata_script_runner startup`) y actualizar
  `~/.config/deployer-db-tunnel/env` en el Mac + `launchctl kickstart -k gui/$(id -u)/cl.deployer.db-tunnel`.
- **Escalar**: `maxScale` y `containerConcurrency` en `services/api.yaml`; procesos de Horizon con
  `HORIZON_MAX_PROCESSES` (subir también la memoria del contenedor `horizon`).
- **Redis**: 256 MB (`REDIS_MAXMEMORY` en `config.sh`), `volatile-lru`, AOF. La VM tiene 1 GB de RAM.

## Pendientes conocidos

- `viewHorizon` (`app/Providers/HorizonServiceProvider.php`) tiene la lista de correos vacía: el
  panel `/horizon` no es accesible en producción hasta definir quién entra.
- `FILESYSTEM_DISK=local` no persiste (igual que en Kubernetes): si la app guarda archivos, pasar a GCS.
- El usuario de base de datos sigue siendo `claudio` (superusuario local); sigue pendiente crear un
  rol sin superusuario.
- Estos YAML y scripts no se han validado contra Google Cloud (no se ha desplegado nada). Lo más
  probable de ajustar en el primer despliegue: CPU fraccionada del sidecar (`cpu: '0.5'`; si Cloud
  Run la rechaza, subir a `'1'`), el rango de `TRUSTED_PROXIES`, y que la asignación de dominio
  deje pasar WebSockets en `ws.deployer.cl`.
- Firewall: la regla `deployer-infra-backends` admite 5432/6379 solo desde la etiqueta de red de
  Cloud Run. La red `default` suele traer además `default-allow-internal` (todo 10.128.0.0/9), que
  deja pasar ese tráfico igual y puede ocultar que la regla por etiqueta no funciona. Revisar con
  `gcloud compute firewall-rules list --filter=network:default` y decidir si se quiere más estricto.
