# syntax=docker/dockerfile:1

# LabMan on FrankenPHP.
# Why FrankenPHP: one self-contained binary (Caddy + PHP) that serves the app with gzip/zstd and sane
# defaults, so the image needs no separate nginx + php-fpm + supervisor trio. That keeps the image
# small, simple to run as a non-root user, and easy to reason about.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------- base: PHP + extensions
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine AS base
# pdo_sqlite is the default database; pdo_mysql / pdo_pgsql make the other two selectable via env.
# pcntl lets the monitor stop cleanly on SIGTERM; sockets gives exact TCP failure reasons.
RUN install-php-extensions pdo_sqlite pdo_mysql pdo_pgsql pcntl sockets opcache
# git: inventory sync clones, commits and pushes to the repository Semaphore reads its inventory from.
RUN apk add --no-cache git
WORKDIR /app

# ---------------------------------------------------------------- vendor (runtime deps only)
FROM composer:2 AS vendor-prod
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY app ./app
COPY database ./database
RUN composer dump-autoload --no-dev --optimize --no-scripts --ignore-platform-reqs

# ---------------------------------------------------------------- vendor (with dev tools, for tests)
FROM composer:2 AS vendor-dev
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY app ./app
COPY database ./database
COPY tests ./tests
RUN composer dump-autoload --optimize --no-scripts --ignore-platform-reqs

# ---------------------------------------------------------------- test target
#   docker build --target test -t labman-test .
#   docker run --rm labman-test
FROM base AS test
COPY --from=vendor-dev /app/vendor ./vendor
COPY . .
RUN chmod +x docker/git-askpass.sh
ENV APP_ENV=testing
CMD ["vendor/bin/phpunit"]

# ---------------------------------------------------------------- runtime (default target, keep last)
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    LARAVEL_STORAGE_PATH=/data/storage \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/tmp \
    XDG_DATA_HOME=/tmp

COPY --from=vendor-prod /app/vendor ./vendor
COPY . .
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/labman-entrypoint
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-labman.ini

# Non-root: a dedicated user owns /data (the only writable place) and the bootstrap cache.
RUN addgroup -S -g 10001 labman \
 && adduser -S -u 10001 -G labman -h /tmp -H -s /sbin/nologin labman \
 && mkdir -p /data bootstrap/cache \
 && rm -f .env \
 && php artisan package:discover --ansi \
 && chown -R labman:labman /data bootstrap/cache \
 && chmod +x /usr/local/bin/labman-entrypoint docker/git-askpass.sh \
 && (setcap -r /usr/local/bin/frankenphp || true)

USER labman
VOLUME /data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'

ENTRYPOINT ["labman-entrypoint"]
