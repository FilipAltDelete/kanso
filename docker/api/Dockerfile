# syntax=docker/dockerfile:1.7
# One build, several targets (same layout as Pimsen): `dev` (tooling and tests,
# source bind-mounted), `worker` (app + console, no web server) and `api`
# (app + Nginx + PHP-FPM).

# ------------------------------------------------------------------ runtime
# Nothing but PHP and the extensions the application needs at run time.
FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache apcu redis zip bcmath \
 && rm /usr/local/bin/install-php-extensions

ARG UID=1000
ARG GID=1000
RUN addgroup -g "$GID" kanso 2>/dev/null || true \
 && adduser -u "$UID" -G "$(getent group "$GID" | cut -d: -f1)" -D -H -s /sbin/nologin kanso 2>/dev/null || true \
 && mkdir -p /tmp/kanso && chown "$UID:$GID" /tmp/kanso

WORKDIR /app
ENV APP_ENV=prod \
    APP_DEBUG=0

# ---------------------------------------------------------------- toolchain
# Composer lives here only, so it never reaches the runtime image.
FROM base AS toolchain
RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer
RUN mkdir -p /tmp/composer && chmod 0777 /tmp/composer

# ------------------------------------------------------------------- build
FROM toolchain AS build
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY backend/ ./
COPY docker/api/php.ini /usr/local/etc/php/conf.d/zz-kanso.ini
# Placeholders only: warming the cache resolves a few env vars, and the real
# values arrive as environment variables when the container runs.
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && mkdir -p var/cache var/log \
 && APP_ENV=prod APP_SECRET=build \
    DATABASE_URL='mysql://build:build@localhost:3306/build?serverVersion=8.4.0' \
    REDIS_URL='redis://localhost:6379' MESSENGER_TRANSPORT_DSN='doctrine://default' \
    php bin/console cache:warmup \
 && chown -R "$(id -u kanso 2>/dev/null || echo 1000)" var

# --------------------------------------------------------------------- app
FROM base AS app
ARG UID=1000
COPY docker/api/php.ini /usr/local/etc/php/conf.d/zz-kanso.ini
COPY --from=build --chown=$UID /app /app
USER $UID
STOPSIGNAL SIGTERM
CMD ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M", "--limit=1000"]

FROM app AS worker

# --------------------------------------------------------------------- api
FROM app AS api
ARG UID=1000
USER root
RUN apk add --no-cache nginx \
 && mkdir -p /run/nginx /var/lib/nginx/logs /var/lib/nginx/tmp \
 && chown -R "$UID" /run/nginx /var/lib/nginx
COPY docker/api/nginx.conf /etc/nginx/nginx.conf
COPY docker/api/php-fpm.conf /usr/local/etc/php-fpm.d/zz-kanso.conf
COPY docker/api/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
USER $UID
EXPOSE 8080
# PHP-FPM's graceful stop: finish in-flight requests, then exit.
STOPSIGNAL SIGQUIT
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm", "-F"]

# ------------------------------------------------------------- dev tooling
FROM toolchain AS dev
ARG UID=1000
ENV APP_ENV=dev APP_DEBUG=1
COPY docker/api/php-dev.ini /usr/local/etc/php/conf.d/zz-kanso.ini
USER $UID
CMD ["php", "-a"]
