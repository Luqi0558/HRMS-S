FROM php:8.2-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl opcache pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

FROM composer:2.8 AS composer-bin

FROM php-base AS vendor

WORKDIR /app
ARG BACKEND_DIR=orbit-hrms-api
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY ${BACKEND_DIR}/composer.json ${BACKEND_DIR}/composer.lock ./
# Package discovery needs artisan and the complete Laravel tree, which is only
# copied in the final stage. Install dependencies here without lifecycle hooks.
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM php-base AS app

WORKDIR /var/www/html
ARG BACKEND_DIR=orbit-hrms-api

COPY --from=vendor /app/vendor ./vendor
COPY ${BACKEND_DIR}/ ./
COPY docker/api/php.ini /usr/local/etc/php/conf.d/orbit-production.ini
COPY docker/api/entrypoint.sh /usr/local/bin/orbit-entrypoint

RUN chmod +x /usr/local/bin/orbit-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

ENTRYPOINT ["/usr/local/bin/orbit-entrypoint"]
CMD ["php-fpm"]
