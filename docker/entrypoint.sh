#!/bin/sh
# LabMan container entrypoint: prepare /data, create the app key, migrate, run the monitor, serve.
# The shared steps come from sunlitlabs/foundation (docker/lib.sh) and update with the package.
set -eu

APP_DATA_DIR="${LABMAN_DATA_DIR:-${APP_DATA_DIR:-/data}}"
export APP_DATA_DIR

FDN_NAME=labman
. /app/vendor/sunlitlabs/foundation/docker/lib.sh

fdn_boot   # storage tree in the data volume, APP_KEY kept in /data/app.key, SQLite file, migrations

# Resident health-check loop, restarted if it ever exits (a node runs none: the main LabMan pushes its results).
if [ "${LABMAN_MODE:-main}" != "node" ]; then
    fdn_supervise monitor php artisan labman:monitor
fi

fdn_serve
