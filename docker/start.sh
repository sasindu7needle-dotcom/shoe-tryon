#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY is required. Generate one with php artisan key:generate --show and set it in Render.' >&2
    exit 1
fi

case "${PORT:-10000}" in
    *[!0-9]*|'') echo 'PORT must be numeric.' >&2; exit 1 ;;
esac
printf 'Listen %s\n' "${PORT:-10000}" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:10000>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf

export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
