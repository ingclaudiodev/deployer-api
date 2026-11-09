# Kubernetes (deployer-cluster, GKE Autopilot)

Manifiestos de deployer-api para el clúster que administra `../cluster-deployer`
(HAProxy, KEDA, Argo Rollouts, GMP, Gateway API). Una carpeta por servicio; el número es el
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
| 4 | `app/` | Rollout blue/green `app` (Octane), Services `app-headless` (activo, lo usa HAProxy), `app`, `app-preview`, PDB, ScaledObject KEDA 2-10 | Redis del clúster, migrate |
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

# 1. Usuario de base de datos propio (Cloud SQL deployerbd, Postgres 18, por Private Service Connect)
gcloud sql users create deployer --instance=deployerbd --password='<generar-una-clave>'

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

# 8. Verificar dentro del clúster
kubectl -n deployer-api get rollout,deploy,sts,svc,scaledobject,pdb,referencegrant,gcpbackendpolicy,healthcheckpolicy
kubectl -n deployer-api port-forward svc/app 8000:8000 &  sleep 5
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/up            # 200
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
  → `app-headless.deployer-api.svc.cluster.local:8000` (leastconn pod a pod; respaldo
  `app.deployer-api.svc.cluster.local:8000`). CORS lo contesta HAProxy para `https://deployer.cl`.
- **WebSocket:** `wss://ws.deployer.cl` → Gateway → Service `reverb` directo (sin HAProxy).
  La HTTPRoute está en el repo del clúster (`cluster-deployer/gateway/routes/reverb.yaml`, ns
  `haproxy`, solo `PathPrefix /app`: el handshake de Pusher/Echo; la API HTTP firmada
  `/apps/{id}/events` no se publica). Como la ruta apunta a un Service de otro namespace, aquí
  va el ReferenceGrant que lo permite, más timeout/drenaje/health (`reverb/policies.yaml`).
  app y horizon publican a `reverb.deployer-api.svc:6001` con las mismas `REVERB_APP_*` del
  Secret compartido. Hasta que exista el Gateway (cluster-deployer paso 7) el WS solo se puede
  probar por port-forward.
- **Base de datos:** Cloud SQL `deployerbd` sin IP pública; endpoint PSC `10.128.0.50` en la red
  del clúster, `sslmode=require` (la instancia es `ENCRYPTED_ONLY`).
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
- **Escalar:** KEDA escala el Rollout `app` 2-10 por CPU/memoria. Reverb con `replicas > 1`
  requiere `REVERB_SCALING_ENABLED=true` (el Redis compartido ya está).

## Pendientes conocidos (código de la app, no de k8s)

- `TRUSTED_PROXIES` (ConfigMap) fija qué proxies se creen para `X-Forwarded-For/Proto/Port`:
  rango de pods `10.4.0.0/17` (HAProxy), GFE `130.211.0.0/22` y `35.191.0.0/16`, e IP del Gateway
  `136.82.30.226`. Sin esto la app generaba URLs `http` (Mixed Content) y el throttle de login
  agrupaba a todos por la IP de HAProxy. No usar `*`: Laravel confiaría en toda la cadena y el
  cliente podría forjar su IP. `X-Forwarded-Host` no se confía (GCLB no lo fija ni lo limpia).
- No hay `config/cors.php`: HAProxy contesta el preflight y añade los headers CORS de respuesta,
  pero si en algún momento la API se expone sin HAProxy, publicar la config de CORS.
- `FILESYSTEM_DISK=local` no persiste: si la app guarda archivos, pasar a GCS.
