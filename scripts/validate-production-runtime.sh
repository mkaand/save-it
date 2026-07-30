#!/usr/bin/env bash
set -euo pipefail

project_dir="${1:-/opt/media-downloader}"
local_base="${2:-http://127.0.0.1:8099}"
public_base="${3:-https://save.allmy.win}"

cd "$project_dir"

for container in \
    media-downloader-app \
    media-downloader-worker \
    media-downloader-scheduler
do
    while IFS= read -r source
    do
        case "$source" in
            /tmp|/tmp/*)
                printf '%s uses unsafe temporary mount source: %s\n' "$container" "$source" >&2
                exit 1
                ;;
        esac
    done < <(docker inspect --format '{{range .Mounts}}{{println .Source}}{{end}}' "$container")
done

for attempt in 1 2 3 4 5
do
    curl -fsS -o /dev/null "$local_base/"
    printf 'local landing attempt %d=PASS\n' "$attempt"
done

for attempt in 1 2 3 4 5
do
    curl -fsS -o /dev/null "$public_base/"
    printf 'public landing attempt %d=PASS\n' "$attempt"
done

curl -fsS -o /dev/null "$local_base/health"
curl -fsS -o /dev/null "$public_base/health"
printf 'health endpoints=PASS\n'

docker exec -u www-data media-downloader-app sh -eu -c '
    test -w /var/www/html/storage/logs
    marker=/var/www/html/storage/logs/.deployment-write-test
    printf "write-test\n" > "$marker"
    rm -f "$marker"
'
printf 'storage log write=PASS\n'

"$project_dir/scripts/validate-assets.sh" "$local_base"
"$project_dir/scripts/validate-assets.sh" "$public_base"

printf 'production runtime validation=PASS\n'
