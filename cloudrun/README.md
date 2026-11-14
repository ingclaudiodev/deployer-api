# Cloud Run

Despliegue de deployer-api en Cloud Run, en reemplazo del clúster GKE (`k8s/`). La imagen es la
misma (`docker/Dockerfile`, target `app`): ya escuchaba en `$PORT`, corre sin root y se construye
en amd64 con Cloud Build. Lo que cambia es quién hace lo que antes hacía el clúster.

> Estado (2026-11-13): la API y Reverb corren en Cloud Run, todavía contra la VM `deployer-infra`
> (túnel al Postgres del Mac + Redis). Lo que describe este documento, la VM `deployer-data` con
> PostgreSQL y Redis, está **preparado y sin aplicar**: falta "Traspaso desde deployer-infra".
> El clúster GKE se eliminó el 2026-11-11; `k8s/` queda solo como referencia.

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
| Postgres del Mac por túnel chisel (`db-tunnel`) | VM `deployer-data`: PostgreSQL 18, base `deployer` | `infra/vm-startup.sh`, `migrar-bd.sh` |
| Redis compartido del clúster | Redis en la misma VM, con clave | `infra/vm-startup.sh` |
| HAProxy (CORS) | Laravel (`config/cors.php`, origen = `FRONTEND_URL`) | — |
| Gateway + certificado wildcard | Asignación de dominio de Cloud Run (certificado administrado) | `setup.sh domains` |

```
navegador ── https://backend.deployer.cl ──▶ Cloud Run deployer-api ─┐  [app: Octane] [horizon]
navegador ── wss://ws.deployer.cl ────────▶ Cloud Run deployer-reverb │
Cloud Scheduler ─ cada 5 min ─▶ Job deployer-scheduler ───────────────┤ Direct VPC egress
deploy.sh ─────────────────────▶ Job deployer-migrate ────────────────┤ (red privada)
                                                                      ▼
                          VM deployer-data (e2-small, Ubuntu 26.04 LTS):  PostgreSQL :5432
                                                                          Redis      :6379
```

## Decisiones que conviene entender

- **La base de datos vive en la VM `deployer-data`**, junto a Redis. Antes era el Postgres del
  Mac, publicado por un túnel chisel en la VM `deployer-infra`: ~180 ms por consulta y
  "connection refused" cada vez que el Mac dormía. Con el traspaso, producción tiene su propia
  base y la del Mac es solo de desarrollo: un `migrate` local ya no llega a producción (lo hace el Job
  `deployer-migrate` en cada despliegue).
- **Un solo disco, sin réplica; respaldo por snapshot diario.** `setup.sh vm` crea la política
  `deployer-data-diario`: un snapshot del disco cada día a las 08:00 UTC, guardado 7 días
  (`BACKUP_*` en `config.sh`). Si la VM o su disco se pierden, se recupera el estado del último
  snapshot: se puede perder hasta un día de datos. Cómo restaurar: "Operar".
- **Solo Cloud Run llega a Postgres y a Redis.** Los servicios y jobs salen a la VPC por una
  subred propia, `deployer-run` (10.8.0.0/24), que nada más usa; el firewall de la VM admite ese
  rango y niega el resto, y `pg_hba` limita además al rol de la app y a su base. Se hace con una
  subred y no con la etiqueta de red `deployer-run` porque Google Cloud no admite etiquetas de
  Cloud Run en reglas de entrada. La exclusividad es una convención, no un candado: no poner VMs
  ni otros servicios en esa subred. El SSH a la VM solo entra por IAP (`deployer-data-ssh-iap` y
  `deployer-data-ssh-deny`). La IP pública de la VM es efímera y solo de salida (`apt`); se administra por SSH con IAP.
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
| VM `e2-small` (2 GB de RAM) | siempre | ~US$12 al mes |
| Disco balanceado de 20 GB | siempre | ~US$2 al mes |
| IP pública efímera de la VM | siempre | ~US$3,65 al mes |
| Snapshots diarios (7 días, incrementales) | siempre | centavos al mes a este tamaño |
| Secret Manager, Cloud Scheduler, Artifact Registry | — | dentro de sus capas gratuitas a este volumen |

Sin tráfico el total es la VM (~US$18 al mes con disco e IP) más los jobs del planificador.

## Primera vez

Requisito: `gcloud` con una cuenta que administre `claudio-orellana-services`
(`gcloud config set account <cuenta>`: la cuenta activa por defecto de este Mac es de otro proyecto
y recibe 403).

Los secretos de producción se perdieron con el clúster. `secrets.sh` los rehace: toma del `.env`
local lo que es igual (clave de la base, webhooks de Slack), genera nuevos `APP_KEY`, claves de
Passport, id y secreto de Reverb y clave de Redis, reutiliza la clave pública de
Reverb que ya tiene el frontend, y pide por teclado el token de ZeptoMail (hay que sacarlo de su
consola: Mail Agent `default` > SMTP). Consecuencia: los tokens y sesiones anteriores dejan de
valer y todos vuelven a iniciar sesión.

`env/common.env` se copió del ConfigMap del repo (`k8s/base/configmap.yaml`), no del clúster vivo,
que ya no se puede consultar. Si algo se había cambiado a mano en el clúster, no está aquí.

```sh
sh cloudrun/setup.sh apis        # 1. APIs
sh cloudrun/setup.sh iam         # 2. cuentas de servicio
sh cloudrun/secrets.sh           # 3. secretos en Secret Manager (pide el token de ZeptoMail)
sh cloudrun/setup.sh vm          # 4. subred de Cloud Run, firewall, VM deployer-data y su respaldo diario
sh cloudrun/migrar-bd.sh         # 5. lleva la base local (esquema y datos) a la VM; pide confirmar
sh cloudrun/deploy.sh            # 6. construye, migra y crea servicios y jobs (DRY_RUN=1 para solo ver los YAML)
sh cloudrun/setup.sh public      # 7. abre la API y Reverb a Internet (antes responden 403)
sh cloudrun/setup.sh scheduler   # 8. Cloud Scheduler -> Job del planificador
#    9. probar por la URL *.run.app (sección Verificar) y recién entonces los dominios (`setup.sh domains`)
```

El orden importa en dos puntos:

- **Secretos antes que la VM**: su script de arranque lee de Secret Manager la clave de Redis y la
  de la base (con ella crea el rol); `setup.sh vm` se niega a crear la VM si faltan.
- **Base antes que el despliegue**: `deploy.sh` ejecuta `migrate` contra la VM. Sobre la base
  restaurada no hay nada pendiente; sobre una base vacía crearía el esquema desde cero, y entonces
  `migrar-bd.sh` ya no restauraría encima. Si urge desplegar sin base: `SKIP_MIGRATE=1`.

## Traspaso desde deployer-infra

Para pasar de la instalación anterior (VM `deployer-infra`: túnel al Postgres del Mac + Redis) a
`deployer-data`. Las dos VM conviven hasta el último paso; la API sigue funcionando contra la
anterior hasta el `deploy.sh`.

```sh
sh cloudrun/secrets.sh access    # 1. la VM podrá leer la clave de la base (no cambia ningún secreto)
sh cloudrun/setup.sh vm          # 2. crea deployer-data; esperar "listos" en el log que indica
sh cloudrun/migrar-bd.sh         # 3. vuelca la base local y la restaura en la VM; compara filas por tabla
git add -A && git commit         # 4. deploy.sh exige el árbol commiteado
sh cloudrun/deploy.sh            # 5. Cloud Run pasa a la subred deployer-run y a la VM nueva
#    6. verificar (sección Verificar): /up, un inicio de sesión real y los logs
sh cloudrun/setup.sh retirar     # 7. imprime cómo borrar deployer-infra, sus IP, el DNS y el túnel del Mac
```

- Entre los pasos 3 y 5 la API todavía escribe en el Postgres del Mac: lo que se registre en esos
  minutos no viaja. Hacerlos seguidos.
- El Redis nuevo parte vacío: todos vuelven a iniciar sesión y un job que estuviera en cola en la
  VM anterior no se ejecuta.
- Vuelta atrás antes del paso 7: `INFRA_IP_NAME=deployer-infra-ip RUN_SUBNET=default sh cloudrun/deploy.sh`
  y la API vuelve al túnel. Hacen falta las dos variables: la VM anterior solo acepta a la subred `default`.

## Dominios

`sh cloudrun/setup.sh domains` imprime los comandos exactos: asignar `backend.deployer.cl` y
`ws.deployer.cl` a los servicios y cambiar sus registros a `CNAME ghs.googlehosted.com.`. El
certificado tarda unos 15 minutos.

Los registros A anteriores de esos hosts apuntaban a la IP del Gateway del clúster, que ya no
existe. Si alguno ya no está en la zona, usar `record-sets create` en vez de `update`/`delete`.

La asignación de dominio de Cloud Run es gratuita pero Google la mantiene en *preview* (puede sumar
latencia). La alternativa soportada es un balanceador HTTPS externo delante de los servicios,
que cuesta del orden de US$18 al mes: no vale la pena mientras el servicio esté en desarrollo.

Del clúster ya se eliminaron la IP del Gateway y el Cloud NAT `deployer-nat` con su router (Cloud
Run sale a Internet directo y la VM tiene IP pública: nada usa NAT). Conviene revisar en la consola
que no queden discos de PVC sueltos.

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

# VM: PostgreSQL y Redis
gcloud compute ssh deployer-data --project=$P --zone=us-central1-a --tunnel-through-iap \
  --command='systemctl --no-pager status postgresql@18-main redis-server | grep -E "●|Active"; sudo -u postgres psql -d deployer -Atc "select count(*) from pg_stat_user_tables"'
```

Tras el primer login por el dominio definitivo, confirmar que el throttle ve la IP real del
cliente y no la de un proxy de Google: si los logs muestran siempre la misma IP, ajustar
`TRUSTED_PROXIES` en `env/common.env` con el rango que aparezca en `X-Forwarded-For`.

## Operar

- **Cambiar una variable**: editar `env/common.env` (o `reverb.env`), commitear y `sh cloudrun/deploy.sh`.
- **Rotar un secreto**: `printf '%s' 'valor' | gcloud secrets versions add <nombre> --project=... --data-file=-`
  y volver a desplegar (las instancias leen `latest` al arrancar). Nombres en `env/secrets.map`.
- **Clave de la base**: nueva versión del secreto `deployer-api-db-password`, reaplicar el arranque
  de la VM (`sudo google_metadata_script_runner startup`: vuelve a fijar la clave del rol) y redesplegar.
- **Consola de la base**: `gcloud compute ssh deployer-data --project=... --zone=us-central1-a
  --tunnel-through-iap`, y dentro `sudo -u postgres psql deployer`. Es la base de producción.
- **Restaurar un respaldo** (se pierde lo escrito después del snapshot; la API queda sin base
  mientras dura). Listar: `gcloud compute snapshots list --project=... --filter='sourceDisk~deployer-data'`.
  Luego borrar la VM conservando nada (`gcloud compute instances delete deployer-data`), crear el
  disco desde el snapshot con el mismo nombre (`gcloud compute disks create deployer-data
  --zone=us-central1-a --source-snapshot=<snapshot> --type=pd-balanced`) y recrear la VM sobre él
  con el comando de `setup.sh vm` cambiando las opciones `--image-*` y `--boot-disk-*` por
  `--disk=name=deployer-data,boot=yes`. Para rescatar solo unos datos, es más seguro montar el
  disco restaurado en una VM aparte y sacar un `pg_dump`. Este procedimiento no se ha ensayado.
- **Cambiar el tamaño de la VM**: detenerla, `gcloud compute instances set-machine-type`, encenderla
  y dejar el tipo nuevo en `VM_MACHINE_TYPE` (`config.sh`). La API queda sin base mientras tanto.
- **Escalar**: `maxScale` y `containerConcurrency` en `services/api.yaml`; procesos de Horizon con
  `HORIZON_MAX_PROCESSES` (subir también la memoria del contenedor `horizon`).
- **Redis**: 256 MB (`REDIS_MAXMEMORY` en `config.sh`), `volatile-lru`, AOF. La VM tiene 2 GB de RAM;
  Postgres usa 256 MB de `shared_buffers` (`POSTGRES_SHARED_BUFFERS`).

## Pendientes conocidos

- `viewHorizon` (`app/Providers/HorizonServiceProvider.php`) tiene la lista de correos vacía: el
  panel `/horizon` no es accesible en producción hasta definir quién entra.
- `FILESYSTEM_DISK=local` no persiste (igual que en Kubernetes): si la app guarda archivos, pasar a GCS.
- La app entra a la base como `claudio`, superusuario (así se pidió). Un rol sin superusuario
  para la app sigue pendiente.
- La restauración de un snapshot no se ha ensayado (ver "Operar").
- Estos YAML y scripts no se han validado contra Google Cloud (no se ha desplegado nada). Lo más
  probable de ajustar en el primer despliegue: CPU fraccionada del sidecar (`cpu: '0.5'`; si Cloud
  Run la rechaza, subir a `'1'`), el rango de `TRUSTED_PROXIES`, y que la asignación de dominio
  deje pasar WebSockets en `ws.deployer.cl`.
- Firewall: Postgres (5432) y Redis (6379) de la VM solo aceptan a la subred `deployer-run`:
  `deployer-data-backends-deny` (prioridad 900) los niega a todos y `deployer-data-backends` (800)
  los abre a ese rango. Si la API no llega a la base o a Redis (timeout, no "connection refused"),
  confirmar que la revisión salió por esa subred (`network-interfaces` en `gcloud run services
  describe`) antes de tocar las reglas.
