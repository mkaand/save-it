FROM composer:2 AS composer

FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY scripts ./scripts
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php:8.2-fpm-bookworm AS app

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        gosu \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        pcntl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN groupmod --gid "${APP_GID}" www-data \
    && usermod --uid "${APP_UID}" --gid "${APP_GID}" www-data

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

COPY . .
COPY --from=frontend /app/public ./public
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-media-downloader.ini
COPY docker/php/entrypoint.sh /usr/local/bin/media-downloader-entrypoint
COPY docker/php/runtime-init.sh /usr/local/bin/media-downloader-runtime-init

RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload \
        --no-dev \
        --no-interaction \
        --optimize \
    && chmod -R a+rX app bootstrap config public resources routes \
    && chmod 0755 \
        /usr/local/bin/media-downloader-entrypoint \
        /usr/local/bin/media-downloader-runtime-init \
        artisan \
    && mkdir -p \
        bootstrap/cache \
        database \
        storage/app \
        storage/app/private \
        storage/app/public \
        storage/framework \
        storage/framework/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache database storage

ENTRYPOINT ["media-downloader-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/public /var/www/html/public

RUN find /var/www/html/public -type d -exec chmod 0755 {} + \
    && find /var/www/html/public -type f -exec chmod 0644 {} +
