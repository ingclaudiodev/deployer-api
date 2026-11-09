# syntax=docker/dockerfile:1

# Imagen de deployer-api servida por Laravel Octane sobre Swoole.
#
# Etapas:
#   base    -> PHP CLI (Alpine) + extensiones + Composer + php.ini de Octane
#   vendor  -> dependencias de Composer sin paquetes de desarrollo
#   assets  -> build de Vite (necesita PHP porque Wayfinder genera rutas con artisan)
#   app     -> imagen final de producción (usuario sin privilegios)
#   dev     -> `app` + Node/npm para trabajar con el código montado desde el host

ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-cli-alpine AS base

ARG UID=1000
ARG GID=1000

ENV PHP_MEMORY_LIMIT=512M \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --no-cache tzdata \
    && install-php-extensions swoole pdo_pgsql pgsql redis pcntl opcache intl zip bcmath sockets \
    && addgroup -g "${GID}" app \
    && adduser -u "${UID}" -G app -D -H -h /var/www/html app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/octane.ini /usr/local/etc/php/conf.d/zz-octane.ini
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html


FROM base AS vendor

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction


FROM vendor AS assets

RUN apk add --no-cache nodejs npm

RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund
RUN npm run build


FROM base AS app

COPY --chown=app:app --from=vendor /var/www/html /var/www/html
COPY --chown=app:app --from=assets /var/www/html/public/build /var/www/html/public/build

RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache

USER app

# 8000: Octane (HTTP). 6001: Reverb (WebSockets) cuando el contenedor arranca con `reverb`.
EXPOSE 8000 6001

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -qO- "http://127.0.0.1:${PORT:-8000}/up" > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["octane"]


FROM app AS dev

USER root
RUN apk add --no-cache nodejs npm git
USER app
