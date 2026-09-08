#!/bin/sh
set -eu

app_root="${APP_ROOT:-/var/www/html}"
runtime_user="${RUNTIME_USER:-www-data}"
runtime_group="${RUNTIME_GROUP:-www-data}"

ensure_directory() {
    install -d \
        -m 0775 \
        -o "$runtime_user" \
        -g "$runtime_group" \
        "$1"
}

for directory in \
    "$app_root/bootstrap/cache" \
    "$app_root/database" \
    "$app_root/storage" \
    "$app_root/storage/app" \
    "$app_root/storage/app/private" \
    "$app_root/storage/app/private/share-preparations" \
    "$app_root/storage/app/public" \
    "$app_root/storage/framework" \
    "$app_root/storage/framework/cache" \
    "$app_root/storage/framework/cache/data" \
    "$app_root/storage/framework/sessions" \
    "$app_root/storage/framework/testing" \
    "$app_root/storage/framework/views" \
    "$app_root/storage/logs"
do
    ensure_directory "$directory"
done

database="$app_root/database/database.sqlite"
if [ ! -e "$database" ]; then
    install -o "$runtime_user" -g "$runtime_group" -m 0660 /dev/null "$database"
else
    chown "$runtime_user:$runtime_group" "$database"
    chmod 0660 "$database"
fi

for log_file in "$app_root"/storage/logs/*.log
do
    if [ -f "$log_file" ]; then
        chown "$runtime_user:$runtime_group" "$log_file"
        chmod 0664 "$log_file"
    fi
done
