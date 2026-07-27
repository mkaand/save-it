FROM composer:2 AS composer

FROM php:8.2-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
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
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-media-downloader.ini
COPY docker/php/entrypoint.sh /usr/local/bin/media-downloader-entrypoint

RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload \
        --no-dev \
        --no-interaction \
        --optimize \
    && chmod 0755 /usr/local/bin/media-downloader-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        database \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache database storage

ENTRYPOINT ["media-downloader-entrypoint"]
CMD ["php-fpm"]
