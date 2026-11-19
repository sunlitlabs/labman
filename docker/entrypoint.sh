#!/bin/sh
# LabMan container entrypoint: prepare /data, create the app key, migrate, run the monitor, serve.
set -eu

DATA="${LABMAN_DATA_DIR:-/data}"
STORAGE="${LARAVEL_STORAGE_PATH:-$DATA/storage}"
export LARAVEL_STORAGE_PATH="$STORAGE"

mkdir -p "$STORAGE/app" "$STORAGE/framework/cache/data" "$STORAGE/framework/sessions" \
         "$STORAGE/framework/views" "$STORAGE/logs"

# APP_KEY: use the env var if given, otherwise generate once and keep it in the data volume
# (never in the image or the repository).
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE="$DATA/app.key"
    if [ ! -s "$KEY_FILE" ]; then
        umask 077
        php -r 'echo "base64:" . base64_encode(random_bytes(32));' > "$KEY_FILE"
        echo "labman: generated a new application key in $KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# SQLite database file (created empty; migrations fill it).
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    export DB_DATABASE="${DB_DATABASE:-$DATA/database.sqlite}"
    [ -e "$DB_DATABASE" ] || : > "$DB_DATABASE"
fi

php artisan migrate --force --no-interaction

# Resident health-check loop, restarted if it ever exits.
(
    while true; do
        php artisan labman:monitor || true
        sleep 2
    done
) &

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
