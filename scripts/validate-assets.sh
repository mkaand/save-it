#!/usr/bin/env bash

set -euo pipefail

base_url="${1:-http://127.0.0.1:8099}"
app_container="${APP_CONTAINER:-media-downloader-app}"
nginx_container="${NGINX_CONTAINER:-media-downloader-nginx}"
validation_dir="$(mktemp -d)"
trap 'rm -rf "$validation_dir"' EXIT

base_url="${base_url%/}"
html_file="$validation_dir/index.html"

curl --fail --silent --show-error "$base_url/" --output "$html_file"

css_path="$(grep -Eo 'href="[^"]*/build/assets/[^"]*\.css"' "$html_file" | head -n 1 | cut -d'"' -f2)"
js_path="$(grep -Eo 'src="[^"]*/build/assets/[^"]*\.js"' "$html_file" | head -n 1 | cut -d'"' -f2)"

if [[ -z "$css_path" || -z "$js_path" ]]; then
    printf 'Unable to find active Vite CSS and JavaScript paths in HTML.\n' >&2
    exit 1
fi

asset_url() {
    local path="$1"

    if [[ "$path" =~ ^https?:// ]]; then
        printf '%s' "$path"
    else
        printf '%s/%s' "$base_url" "${path#/}"
    fi
}

asset_path() {
    local value="$1"

    if [[ "$value" =~ ^https?:// ]]; then
        value="${value#*://}"
        value="${value#*/}"
    fi

    printf '%s' "${value#/}"
}

validate_asset() {
    local kind="$1"
    local path="$2"
    local expected_type="$3"
    local headers="$validation_dir/$kind.headers"
    local body="$validation_dir/$kind.body"
    local status
    local size

    status="$(curl --silent --show-error --location \
        --dump-header "$headers" \
        --output "$body" \
        --write-out '%{http_code}' \
        "$(asset_url "$path")")"
    size="$(wc -c < "$body")"

    [[ "$status" == "200" ]]
    [[ "$size" -gt 0 ]]
    grep -Eiq "^content-type:[[:space:]]*$expected_type" "$headers"

    printf '%s path=%s status=%s bytes=%s\n' "$kind" "$path" "$status" "$size"
}

validate_asset "css" "$css_path" 'text/css'
validate_asset "js" "$js_path" '(application|text)/(javascript|x-javascript)'

docker exec "$app_container" cat /var/www/html/public/build/manifest.json > "$validation_dir/app-manifest.json"
docker exec "$nginx_container" cat /var/www/html/public/build/manifest.json > "$validation_dir/nginx-manifest.json"

cmp --silent "$validation_dir/app-manifest.json" "$validation_dir/nginx-manifest.json"

app_sha="$(sha256sum "$validation_dir/app-manifest.json" | cut -d' ' -f1)"
nginx_sha="$(sha256sum "$validation_dir/nginx-manifest.json" | cut -d' ' -f1)"
[[ "$app_sha" == "$nginx_sha" ]]

while IFS= read -r asset; do
    docker exec "$app_container" test -s "/var/www/html/public/build/$asset"
    docker exec "$nginx_container" test -s "/var/www/html/public/build/$asset"
done < <(jq -r 'to_entries[].value | .file, (.css[]? // empty)' "$validation_dir/app-manifest.json" | sort -u)

css_file="$(asset_path "$css_path")"
js_file="$(asset_path "$js_path")"

docker exec "$app_container" test -s "/var/www/html/public/$css_file"
docker exec "$app_container" test -s "/var/www/html/public/$js_file"
docker exec "$nginx_container" test -s "/var/www/html/public/$css_file"
docker exec "$nginx_container" test -s "/var/www/html/public/$js_file"

printf 'manifest sha256=%s app=match nginx=match\n' "$app_sha"
printf 'asset validation=PASS\n'
