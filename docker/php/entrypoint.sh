#!/bin/sh
set -eu

for path in \
    /var/www/html/bootstrap/cache \
    /var/www/html/database \
    /var/www/html/storage
do
    chown -R www-data:www-data "$path"
done

mkdir -p \
    /var/www/html/storage/app/private \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs

if [ ! -e /var/www/html/database/database.sqlite ]; then
    install -o www-data -g www-data -m 0660 /dev/null /var/www/html/database/database.sqlite
fi

if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi

exec gosu www-data "$@"
