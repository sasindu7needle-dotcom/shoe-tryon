# 1. Front-end assets (Node is not needed at runtime).
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY scripts ./scripts
# Build the bundles, then pre-compress them and the tracking model so the web
# server never spends CPU compressing the 19 MB network per visitor.
RUN npm run build \
    && node scripts/precompress.mjs public/vendor public/models public/build


# 2. Use the same PHP version and extensions for dependencies and runtime.
FROM dunglas/frankenphp:1-php8.4-trixie AS php-base
RUN install-php-extensions intl opcache pdo_sqlite pdo_mysql zip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM php-base AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader


# 3. Runtime: FrankenPHP serves PHP and static files from one process, with
#    automatic HTTPS. One container is enough for a small VPS because all
#    tracking runs in the shopper's browser.
FROM php-base AS app

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
# Built bundles plus the pre-compressed .br/.gz copies.
COPY --from=assets /app/public ./public
COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN test -s public/vendor/webar-rocks/WebARRocksHand.js \
    && test -s public/vendor/webar-rocks/neural-nets/NN_FOOT_31.json.br \
    && test -s public/models/occluder.glb \
    && test -d public/models/samples \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && composer dump-autoload --no-dev --optimize \
    && rm -rf resources/models/source tests

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/app/database.sqlite \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    SERVER_NAME=:80

EXPOSE 80 443 443/udp

ENTRYPOINT ["entrypoint.sh"]
