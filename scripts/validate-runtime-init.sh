#!/usr/bin/env bash
set -euo pipefail

image="${1:-media-downloader-app:local}"
fixture="$(mktemp -d)"

cleanup() {
    docker run --rm \
        --network none \
        --security-opt no-new-privileges:true \
        -v "$fixture:/fixture" \
        --entrypoint sh \
        "$image" \
        -eu -c 'find /fixture -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'
    rm -rf "$fixture"
}

trap cleanup EXIT

install -d -m 0700 \
    "$fixture/storage/logs" \
    "$fixture/database" \
    "$fixture/bootstrap-cache"
printf 'existing-log\n' > "$fixture/storage/logs/laravel.log"
chmod 0600 "$fixture/storage/logs/laravel.log"

run_validation() {
    docker run --rm \
        --read-only \
        --tmpfs /tmp:rw,noexec,nosuid,size=16m \
        --tmpfs /run:rw,noexec,nosuid,size=4m \
        -e CACHE_STORE=array \
        -e QUEUE_CONNECTION=sync \
        -e SESSION_DRIVER=array \
        -v "$fixture/storage:/var/www/html/storage" \
        -v "$fixture/database:/var/www/html/database" \
        -v "$fixture/bootstrap-cache:/var/www/html/bootstrap/cache" \
        --entrypoint media-downloader-entrypoint \
        "$image" \
        sh -eu -c '
            required="
                /var/www/html/storage/app
                /var/www/html/storage/app/private
                /var/www/html/storage/app/public
                /var/www/html/storage/framework/cache/data
                /var/www/html/storage/framework/sessions
                /var/www/html/storage/framework/testing
                /var/www/html/storage/framework/views
                /var/www/html/storage/logs
                /var/www/html/bootstrap/cache
            "
            for directory in $required; do
                test -d "$directory"
                test -w "$directory"
                test "$(stat -c %U:%G "$directory")" = www-data:www-data
                test "$(stat -c %a "$directory")" = 775
            done
            test "$(stat -c %U:%G /var/www/html/storage/logs/laravel.log)" = www-data:www-data
            test "$(stat -c %a /var/www/html/storage/logs/laravel.log)" = 664
            test -w /var/www/html/database/database.sqlite
            printf "runtime-write\n" > /var/www/html/storage/logs/runtime-init-test.log
            php artisan schedule:run --no-interaction
            php artisan queue:work sync --stop-when-empty --no-interaction
        '
}

run_validation
run_validation

if find "$fixture/storage" "$fixture/database" "$fixture/bootstrap-cache" \
    -perm -0002 -print -quit | grep -q .
then
    printf 'Runtime initialization created a world-writable path.\n' >&2
    exit 1
fi

printf 'runtime initialization=PASS\n'
