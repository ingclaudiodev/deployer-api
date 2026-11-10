# Kubernetes (deployer-cluster, GKE Autopilot)

Manifiestos de deployer-api para el clúster que administra `../cluster-deployer`
(HAProxy, KEDA + HTTP Add-on, Argo Rollouts, GMP, Gateway API). Una carpeta por servicio; el número es el
orden de aplicación. El tag de la imagen se fija una sola vez en `k8s/kustomization.yaml`.

## Versión de la imagen

El tag de la imagen **es la versión de `version.txt`** (semver, p. ej. `1.4.2`), nunca `:latest`
ni el sha a secas. Cloud Build (`cloudbuild.yaml`) sube dos tags por build: `:<version>`, que es el
que despliega kustomize, y `:<version>-<sha>`, para saber qué commit exacto produjo esa versión
(también va en las etiquetas OCI `org.opencontainers.image.version` y `.revision`). Para publicar:
subir `version.txt`, commitear y correr `sh k8s/deploy.sh`, que exige árbol commiteado y se niega a
reconstruir una versión que ya existe en Artifact Registry (`FORCE=1` para republicarla a propósito).

| # | Carpeta | Qué es | Depende de |
|---|---|---|---|
| 1 | `base/` | Namespace `deployer-api` (ComputeClass `n4-hyperdisk` por defecto, etiqueta del Gateway), ConfigMap `deployer-api-env`, plantilla del Secret `deployer-api-secrets` | cluster-deployer pasos 1-6c |
| 2 | (Redis) | No está en este repo: es el Redis COMPARTIDO del clúster, `cluster-deployer/redis` (paso 5b), ns `redis`, 1 GiB, `redis.redis.svc.cluster.local:6379` | cluster-deployer paso 5b |
| 3 | `migrate/` | Job `migrate` (`php artisan migrate --force`); se borra y reaplica en cada despliegue | base, Secret, Cloud SQL alcanzable |
| 4 | `app/` | Rollout blue/green `app` (Octane), Services `app-headless` (activo, lo usa el interceptor de KEDA), `app`, `app-preview`, PDB, HTTPScaledObject KEDA 0-2 (scale-to-zero) | Redis del clúster, migrate, KEDA HTTP Add-on (cluster-deployer paso 6e) |
| 5 | `horizon/` | Deployment `horizon` (colas), 1 réplica, apagado limpio | Redis del clúster, migrate |
| 6 | `scheduler/` | Deployment `scheduler` (`schedule:work`), 1 réplica, Recreate | Redis del clúster |
| 7 | `reverb/` | Deployment + Service `reverb`, ReferenceGrant y políticas del balanceador (`policies.yaml`). La HTTPRoute `ws.deployer.cl` NO está aquí: vive con todas las rutas del clúster en `cluster-deployer/gateway/routes/reverb.yaml` | base; Gateway del clúster (cluster-deployer paso 7) para que el WS sea público |

## Dónde está el `.env`

No hay archivo `.env` en Kubernetes (y `.dockerignore` lo excluye de la imagen): Laravel lee las
variables del entorno del contenedor, que llegan por `envFrom` desde tres objetos.

| En local (`.env`) | En el clúster | Archivo |
|---|---|---|
| Variables no secretas de app, horizon, scheduler y migrate | ConfigMap `deployer-api-env` | `k8s/base/configmap.yaml` |
| Variables no secretas de Reverb (anuncia otro host) | ConfigMap `reverb-config` | `k8s/reverb/configmap.yaml` |
| `APP_KEY`, `DB_PASSWORD`, `REVERB_APP_*`, claves de Passport, webhooks, usuario y token SMTP de ZeptoMail | Secret `deployer-api-secrets` (se crea a mano, nunca se commitea) | plantilla `k8s/base/secret.example.yaml` |
| Credenciales del túnel a la base local (`users.json`, clave del servidor chisel) | Secret `db-tunnel-auth` (a mano) | plantilla `k8s/db-tunnel/secret.example.yaml` |
| `OCTANE_WORKERS`, `PHP_MEMORY_LIMIT` y similares por proceso | `env:` de cada Deployment/Rollout | `k8s/app/rollout.yaml`, etc. |

Claves de `.env.example` que a propósito no están en el clúster: `AWS_*` y `MEMCACHED_*` (no se
usan), `REDIS_PASSWORD` (Redis interno sin clave), `SESSION_DOMAIN/PATH/ENCRYPT` (API con tokens,
defaults de Laravel), `LOG_STACK` (solo aplica con `LOG_CHANNEL=stack`), `VITE_APP_NAME` y
`APP_FAKER_LOCALE` (build/desarrollo). Cambiar un valor del ConfigMap requiere `rollout restart`
del servicio; cambiar el Secret, recrearlo con `--dry-run=client -o yaml | kubectl apply -f -` y
después el `rollout restart`.

## Primera vez

Requisitos en el Mac: `gcloud`, `kubectl` (contexto `deployer-cluster`), `kustomize` (`brew install kustomize`;
si no está, editar `newTag` a mano en `k8s/kustomization.yaml`) y `php` para generar claves.

```sh
# 0. Repositorio de imágenes y Cloud Build (una vez)
gcloud services enable cloudbuild.googleapis.com artifactregistry.googleapis.com
gcloud artifacts repositories create deployer --repository-format=docker --location=us-central1 --description="Imágenes de deployer-api"

# 1. Base de datos: es el Postgres local del Mac por el túnel de k8s/db-tunnel (ver "Cómo encaja").
#    Crear el Secret db-tunnel-auth (k8s/db-tunnel/secret.example.yaml) y dejar corriendo el
#    cliente en el Mac (launchd cl.deployer.db-tunnel) antes del paso 6.

# 2. Imagen (Cloud Build: los nodos son amd64; la imagen local del Mac es arm64). El tag es la
#    versión de version.txt; `sh k8s/deploy.sh` hace esto y los pasos 6 y 7. A mano:
TAG=$(tr -d '[:space:]' < version.txt); SHA=$(git rev-parse --short HEAD)
gcloud builds submit --config cloudbuild.yaml --substitutions=_TAG=$TAG,_SHA=$SHA .
(cd k8s && kustomize edit set image us-central1-docker.pkg.dev/claudio-orellana-services/deployer/deployer-api=:$TAG)

# 3. Namespace + config
kubectl apply -k k8s/base

# 4. Secret (ver k8s/base/secret.example.yaml; las claves de Passport con --from-file).
#    storage/oauth-*.key del Mac son las claves de DESARROLLO: para producción generar un par nuevo
#    (php artisan passport:keys --force en una copia limpia, o openssl) y guardarlo fuera del repo.
kubectl -n deployer-api create secret generic deployer-api-secrets \
  --from-literal=APP_KEY="$(php artisan key:generate --show)" \
  --from-literal=DB_PASSWORD='<la-del-paso-1>' \
  --from-literal=REVERB_APP_ID='...' --from-literal=REVERB_APP_KEY='...' --from-literal=REVERB_APP_SECRET='...' \
  --from-file=PASSPORT_PRIVATE_KEY=storage/oauth-private.key --from-file=PASSPORT_PUBLIC_KEY=storage/oauth-public.key \
  --from-literal=LOG_SLACK_WEBHOOK_URL='' --from-literal=SLACK_WEBHOOK_URL='' \
  --from-literal=MAIL_USERNAME='emailapikey' --from-literal=MAIL_PASSWORD='<Send Mail Token SMTP de ZeptoMail>'

# 5. Redis: es el compartido del clúster (cluster-deployer paso 5b). Solo comprobar que responde:
kubectl -n redis exec statefulset/redis -- redis-cli ping     # PONG

# 6. Migraciones
kubectl -n deployer-api delete job migrate --ignore-not-found
sed "s/:latest/:$TAG/" k8s/migrate/job.yaml | kubectl apply -f -
kubectl -n deployer-api wait --for=condition=complete job/migrate --timeout=300s
kubectl -n deployer-api logs job/migrate

# 7. App, Horizon, scheduler, Reverb: SIEMPRE desde la raíz k8s/ (es donde está el tag de la
#    imagen; `kubectl apply -k k8s/app` renderizaría :latest). base se reaplica sin cambios.
kubectl apply -k k8s
kubectl argo rollouts get rollout app -n deployer-api --watch     # plugin: brew install argoproj/tap/kubectl-argo-rollouts

# 8. Verificar dentro del clúster. La API puede estar en 0 pods: la prueba válida es por HAProxy
#    con el Host público (pasa por el interceptor de KEDA, que la despierta); un port-forward a
#    svc/app con 0 pods falla.
kubectl -n deployer-api get rollout,deploy,sts,svc,httpscaledobject,scaledobject,pdb,referencegrant,gcpbackendpolicy,healthcheckpolicy
kubectl -n haproxy port-forward svc/haproxy-service 18080:80 &  sleep 5
curl -s -o /dev/null -m 300 -w '%{http_code} %{time_total}s\n' -H 'Host: backend.deployer.cl' http://localhost:18080/up   # 200 (la primera tarda si estaba en 0)
kill %1
kubectl -n deployer-api exec deploy/horizon -- php artisan horizon:status    # Horizon is running.
```

## Cada despliegue

```sh
# 1. subir la versión y commitear
echo 1.4.2 > version.txt && git add -A && git commit -m "v1.4.2"
# 2. construir :1.4.2 y :1.4.2-<sha>, fijar el tag, migrar, aplicar y commitear k8s/kustomization.yaml
sh k8s/deploy.sh                 # BUILD_ONLY=1 solo construye; FORCE=1 republica una versión existente
```

Equivale a: `gcloud builds submit --substitutions=_TAG=<version>,_SHA=<sha>`, `kustomize edit set image ...=:<version>`,
borrar y reaplicar el Job `migrate` con ese tag, `kubectl apply -k k8s` (Rollout blue/green de app; Recreate de
horizon y scheduler; rolling de reverb) y `git commit -m "deploy <version>"`.

## Cómo encaja con el clúster

- **Entrada HTTP:** `https://backend.deployer.cl` → Gateway → HAProxy (backend `backend-deployer`)
  → interceptor del KEDA HTTP Add-on (`keda-add-ons-http-interceptor-proxy.keda.svc:8080`) → pods
  Ready de `app-headless` (directo, por endpoints). Respaldo de HAProxy si el interceptor no
  responde: `app.deployer-api.svc.cluster.local:8000`. CORS lo contesta HAProxy para `https://deployer.cl`.
- **WebSocket:** `wss://ws.deployer.cl` → Gateway → Service `reverb` directo (sin HAProxy).
  La HTTPRoute está en el repo del clúster (`cluster-deployer/gateway/routes/reverb.yaml`, ns
  `haproxy`, solo `PathPrefix /app`: el handshake de Pusher/Echo; la API HTTP firmada
  `/apps/{id}/events` no se publica). Como la ruta apunta a un Service de otro namespace, aquí
  va el ReferenceGrant que lo permite, más timeout/drenaje/health (`reverb/policies.yaml`).
  app y horizon publican a `reverb.deployer-api.svc:6001` con las mismas `REVERB_APP_*` del
  Secret compartido. Hasta que exista el Gateway (cluster-deployer paso 7) el WS solo se puede
  probar por port-forward.
- **Base de datos:** el Postgres 18 LOCAL del Mac de desarrollo (base `deployer`, usuario `claudio`),
  por un túnel inverso (`k8s/db-tunnel`, desde 2026-11-10; Cloud SQL se eliminó por costo). El Mac
  está detrás de CGNAT (no acepta conexiones entrantes), así que es él quien se conecta: un cliente
  [chisel](https://github.com/jpillora/chisel) (`~/.local/bin/chisel`, lanzado por launchd
  `~/Library/LaunchAgents/cl.deployer.db-tunnel.plist`, config en `~/.config/deployer-db-tunnel/`)
  abre un WebSocket por `https://db-tunnel.deployer.cl` (HTTPRoute en
  `cluster-deployer/gateway/routes/db-tunnel.yaml`, directo al Service `db-tunnel:8080`, cert
  wildcard) y el servidor chisel del Deployment `db-tunnel` publica el `127.0.0.1:5432` del Mac en el
  Service `postgres-local:5432` (= `DB_HOST`). `DB_SSLMODE=disable`: el tramo Internet ya va por TLS.
  Consecuencias: cada consulta cuesta ~180 ms (Chile ↔ us-central1; el handshake ~1 s, Octane y
  Horizon reutilizan la conexión); si el Mac duerme o pierde red la app da `connection refused`
  hasta que el cliente reconecta (reintenta sin límite; el balanceador cierra el WS cada 24 h); y
  la base es la MISMA del desarrollo local: un `migrate`/seeder en el Mac se ve en producción.
  Diagnóstico: `tail ~/Library/Logs/deployer-db-tunnel.log` en el Mac (`Connected (Latency ...)`),
  `kubectl -n deployer-api logs deploy/db-tunnel` (`session#N: Open ... proxy#R:5432=>5432: Listening`),
  `kubectl -n deployer-api exec deploy/horizon -- php artisan db:show`. Rotar credenciales: recrear
  `db-tunnel-auth`, `rollout restart deploy/db-tunnel`, actualizar `~/.config/deployer-db-tunnel/env`
  y `launchctl kickstart -k gui/$(id -u)/cl.deployer.db-tunnel`. Si el pod `db-tunnel` cambia de
  nodo, el balanceador tarda ~2 min en dar por sano el NEG nuevo y el cliente reintenta cada 15 s:
  por eso lleva PDB `minAvailable: 1` y `safe-to-evict: "false"` (`db-tunnel/pdb.yaml`).
- **Nodos:** el namespace usa la ComputeClass `n4-hyperdisk` (cluster-deployer paso 6c) porque la
  cuota SSD del proyecto no permite más nodos Autopilot por defecto. Todo corre en nodos n4.
- **Redis:** compartido con el resto del clúster (`cluster-deployer/redis`, ns `redis`, 1 GiB,
  `volatile-lru`: cache y sesiones se desalojan antes que las colas). `REDIS_HOST=redis.redis.svc.cluster.local`.
- **Correo:** Zoho ZeptoMail por SMTP, `smtp.zeptomail.com:465` (TLS implícito), usuario
  `emailapikey` y Send Mail Token en el Secret. El remitente (`MAIL_FROM_ADDRESS`) debe ser del
  dominio verificado en la consola de ZeptoMail (`backend.deployer.cl`). Los pods son privados y
  salen por el Cloud NAT `deployer-nat`. Quien habla con ZeptoMail es Horizon (todo correo es
  `ShouldQueue`). Cargar o rotar el token SIN recrear el Secret entero (un `create --dry-run | apply`
  con solo estas dos claves borraría APP_KEY, DB_PASSWORD y Passport):
  `kubectl -n deployer-api patch secret deployer-api-secrets --type merge -p '{"stringData":{"MAIL_USERNAME":"emailapikey","MAIL_PASSWORD":"<token>"}}'`
  y después `kubectl -n deployer-api rollout restart deploy/horizon` (app es un Rollout de Argo:
  `kubectl argo rollouts restart app -n deployer-api`). Prueba síncrona desde el pod NUEVO de
  Horizon, que salta la cola y valida NAT + TLS + credenciales + dominio remitente:
  `kubectl -n deployer-api exec deploy/horizon -- php artisan tinker --execute 'Mail::raw("prueba", fn ($m) => $m->to("tu@correo")->subject("ZeptoMail OK"));'`
- **Escalar (scale-to-zero):** el KEDA HTTP Add-on escala el Rollout `app` entre 0 y 2 por
  peticiones en vuelo (`k8s/app/httpscaledobject.yaml`: 4 por pod, 15 min sin tráfico → 0 pods).
  Dormida, la primera petición a `backend.deployer.cl` la retiene el interceptor hasta que haya un
  pod Ready (tope 300 s): 10-30 s si el nodo n4 sigue vivo, 1-3 min si Autopilot tuvo que crear
  uno. Horizon, scheduler y Reverb NO duermen (1 réplica fija). Cualquier monitor externo o cron
  que pegue a la API cada pocos minutos la mantiene despierta y anula el ahorro.
  El operator del add-on crea el ScaledObject `app` (trigger `external-push`) a partir del
  HTTPScaledObject; no editar ese ScaledObject a mano. Migración desde el ScaledObject por
  CPU/memoria que existía antes (`kubectl apply -k` no poda): instalar el add-on
  (cluster-deployer paso 6e), `kubectl -n deployer-api delete scaledobject app`, `kubectl apply -k k8s`
  y después cambiar HAProxy al interceptor; el orden importa y está en el script de ese paso.
  Reverb con `replicas > 1` requiere `REVERB_SCALING_ENABLED=true` (el Redis compartido ya está).

## Pendientes conocidos (código de la app, no de k8s)

- `TRUSTED_PROXIES` (ConfigMap) fija qué proxies se creen para `X-Forwarded-For/Proto/Port`:
  rango de pods `10.4.0.0/17` (HAProxy), GFE `130.211.0.0/22` y `35.191.0.0/16`, e IP del Gateway
  `136.82.30.226`. Sin esto la app generaba URLs `http` (Mixed Content) y el throttle de login
  agrupaba a todos por la IP de HAProxy. No usar `*`: Laravel confiaría en toda la cadena y el
  cliente podría forjar su IP. `X-Forwarded-Host` no se confía (GCLB no lo fija ni lo limpia).
- No hay `config/cors.php`: HAProxy contesta el preflight y añade los headers CORS de respuesta,
  pero si en algún momento la API se expone sin HAProxy, publicar la config de CORS.
- `FILESYSTEM_DISK=local` no persiste: si la app guarda archivos, pasar a GCS.
