# Cloud Run

Despliegue de deployer-api en Cloud Run, en reemplazo del clúster GKE (`k8s/`). La imagen es la
misma (`docker/Dockerfile`, target `app`): ya escuchaba en `$PORT`, corre sin root y se construye
en amd64 con Cloud Build. Lo que cambia es quién hace lo que antes hacía el clúster.

> Estado (2026-11-11): el clúster GKE **se eliminó** por costo; producción está caída hasta completar
> estos pasos. `k8s/` queda solo como referencia de cómo estaba: ya no es un rollback.

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
  necesita otra casa con IP pública: la VM. En el Mac se mantiene la URL y el usuario; solo cambia
  la huella del servidor (la clave anterior se perdió con el clúster; `secrets.sh` indica cómo
  actualizarla). Siguen valiendo los ~180 ms por consulta, el "connection refused" si el Mac
  duerme y que es la MISMA base del desarrollo.
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

Requisito: `gcloud` con una cuenta que administre `claudio-orellana-services`
(`gcloud config set account <cuenta>`: la cuenta activa por defecto de este Mac es de otro proyecto
y recibe 403).

Los secretos de producción se perdieron con el clúster. `secrets.sh` los rehace: toma del `.env`
local lo que es igual (clave de la base, webhooks de Slack), genera nuevos `APP_KEY`, claves de
Passport, id y secreto de Reverb, clave de Redis y clave del túnel, reutiliza la clave pública de
Reverb que ya tiene el frontend, y pide por teclado el token de ZeptoMail (hay que sacarlo de su
consola: Mail Agent `default` > SMTP). Consecuencia: los tokens y sesiones anteriores dejan de
valer y todos vuelven a iniciar sesión.

`env/common.env` se copió del ConfigMap del repo (`k8s/base/configmap.yaml`), no del clúster vivo,
que ya no se puede consultar. Si algo se había cambiado a mano en el clúster, no está aquí.

```sh
sh cloudrun/setup.sh apis        # 1. APIs
sh cloudrun/setup.sh iam         # 2. cuentas de servicio
sh cloudrun/secrets.sh           # 3. secretos en Secret Manager (pide el token de ZeptoMail)
sh cloudrun/setup.sh vm          # 4. VM deployer-infra: IPs, firewall, chisel y Redis
sh cloudrun/setup.sh domains     # 5. imprime los comandos de DNS; ejecutar SOLO el del túnel (punto 1)
#    y actualizar la huella en el Mac con los dos comandos que imprimió secrets.sh
tail -f ~/Library/Logs/deployer-db-tunnel.log   # 6. esperar "Connected (Latency ...)"
sh cloudrun/deploy.sh            # 7. construye, migra y crea servicios y jobs (DRY_RUN=1 para solo ver los YAML)
sh cloudrun/setup.sh public      # 8. abre la API y Reverb a Internet (antes responden 403)
sh cloudrun/setup.sh scheduler   # 9. Cloud Scheduler -> Job del planificador
#    10. probar por la URL *.run.app (sección Verificar) y recién entonces los dominios (punto 2 de `domains`)
```

El orden importa en dos puntos:

- **Secretos antes que la VM**: su script de arranque lee de Secret Manager las credenciales del
  túnel y la clave de Redis; si no existen, falla (se ve en el log de arranque que imprime el paso 4).
- **Túnel antes que el despliegue**: `deploy.sh` ejecuta `migrate`, que necesita llegar al Postgres
  del Mac por la VM. Sin túnel falla con "connection refused" y no despliega nada. El servidor
  chisel pide su certificado a Let's Encrypt en la primera conexión, por eso necesita que
  `db-tunnel.deployer.cl` ya apunte a la VM. Si urge desplegar sin base: `SKIP_MIGRATE=1`.

## Dominios

`sh cloudrun/setup.sh domains` imprime los comandos exactos con las IP reales:

1. **Túnel**: registro A de `db-tunnel.deployer.cl` → IP pública de la VM (paso 5 de arriba).
2. **API y WebSocket**: asignar `backend.deployer.cl` y `ws.deployer.cl` a los servicios y cambiar
   sus registros a `CNAME ghs.googlehosted.com.`. El certificado tarda unos 15 minutos.

Los registros A actuales de esos tres hosts apuntan a la IP del Gateway del clúster, que ya no
existe. Si alguno ya no está en la zona, usar `record-sets create` en vez de `update`/`delete`.

La asignación de dominio de Cloud Run es gratuita pero Google la mantiene en *preview* (puede sumar
latencia). La alternativa soportada es un balanceador HTTPS externo delante de los servicios,
que cuesta del orden de US$18 al mes: no vale la pena mientras el servicio esté en desarrollo.

Restos del clúster que pueden seguir cobrando y conviene revisar en la consola: el Cloud NAT
`deployer-nat`, la IP reservada del Gateway (`deployer-gateway-ip`), discos de PVC que hayan
quedado y los recursos PSC huérfanos de la antigua Cloud SQL (`deployerbd-psc`, `deployerbd-psc-ip`).

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
