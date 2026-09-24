#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="${1:-$(tr -d '[:space:]' < "$project_dir/VERSION")}"

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "Ungültige Version: $version" >&2
    exit 1
fi

release_name="gymslunity-v${version}"
output_dir="$project_dir/dist"
work_dir="$(mktemp -d)"
app_dir="$work_dir/$release_name"

cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

mkdir -p "$app_dir" "$output_dir"
git -C "$project_dir" archive HEAD | tar -x -C "$app_dir"
touch "$app_dir/database/database.sqlite"

COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --working-dir="$app_dir" \
    --no-dev \
    --no-scripts \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist

npm --prefix "$app_dir" ci
npm --prefix "$app_dir" run build

rm -rf -- \
    "$app_dir/node_modules" \
    "$app_dir/tests" \
    "$app_dir/.github" \
    "$app_dir/dist"

find "$app_dir/storage" -type f ! -name '.gitignore' -delete
find "$app_dir/bootstrap/cache" -type f ! -name '.gitignore' -delete

archive="$output_dir/$release_name.tar.gz"
tar -C "$work_dir" -czf "$archive" "$release_name"
sha256sum "$archive" > "$archive.sha256"

echo "$archive"
echo "$archive.sha256"
