#!/bin/sh
set -eu

/usr/local/bin/media-downloader-runtime-init

if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi

exec gosu www-data "$@"
