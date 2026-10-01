#!/bin/sh
# Prepare the writable volume, migrate, cache config, then hand over to FrankenPHP.
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY is required. Generate one with: docker compose run --rm app php artisan key:generate --show' >&2
    exit 1
fi

mkdir -p storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-/app/storage/app/database.sqlite}"
fi

# public/storage points into the volume, so it is recreated on every start.
rm -f public/storage
php artisan storage:link --quiet

php artisan migrate --force
php artisan optimize

exec frankenphp run --config /etc/frankenphp/Caddyfile
