#!/usr/bin/env sh
set -eu

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is required in production." >&2
        exit 1
    fi

    echo "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
    until mysqladmin ping \
        -h "${DB_HOST:-mysql}" \
        -P "${DB_PORT:-3306}" \
        -u "${DB_USERNAME}" \
        -p"${DB_PASSWORD}" \
        --silent; do
        sleep 2
    done

    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache

    php artisan migrate --force --no-interaction

    if [ "${RUN_TENANT_MIGRATIONS:-true}" = "true" ]; then
        php artisan tenants:migrate --force --no-interaction
    fi

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"

