#!/usr/bin/env bash
#
# Installs a GymSLunity release archive into one instance of the public demo
# and resets it to the sample data. Runs on the demo server, as the forced
# command of the deploy key (see docs/demo.md):
#
#   ssh gymslunity-demo@demo.example.org next < gymslunity-v1.0.0.tar.gz
#
# The instance is chosen by the SSH command (or the first argument when
# called directly) and must be "release" or "next".

set -euo pipefail

root="${DEMO_ROOT:-/srv/gymslunity-demo}"
target="${SSH_ORIGINAL_COMMAND:-${1:-}}"
max_archive_bytes=$((300 * 1024 * 1024))

case "$target" in
    release | next) ;;
    *)
        echo "Ziel muss release oder next sein." >&2
        exit 2
        ;;
esac

base="$root/$target"
env_file="$base/shared/.env"
if [[ ! -f "$env_file" ]]; then
    echo "$env_file fehlt." >&2
    exit 1
fi
# The reset deletes all data. Never run it against an instance with real data.
if ! grep -qx 'DEMO_MODE=true' "$env_file"; then
    echo "$env_file enthält nicht DEMO_MODE=true, Abbruch." >&2
    exit 1
fi

release_dir="$base/releases/$(date -u +%Y%m%d%H%M%S)"
mkdir -p "$release_dir"
cleanup_failed() {
    rm -rf -- "$release_dir"
}
trap cleanup_failed ERR

head -c "$max_archive_bytes" | tar -xz -C "$release_dir" --strip-components=1 --no-same-owner
if [[ ! -f "$release_dir/artisan" ]]; then
    echo "Das Archiv enthält keine GymSLunity-Installation." >&2
    exit 1
fi

rm -rf -- "$release_dir/storage"
ln -s "$base/shared/storage" "$release_dir/storage"
ln -s "$env_file" "$release_dir/.env"
ln -sfn ../storage/app/public "$release_dir/public/storage"

cd "$release_dir"
php artisan demo:reset
php artisan optimize

ln -sfn "$release_dir" "$base/current.new"
mv -T "$base/current.new" "$base/current"
trap - ERR
php artisan queue:restart

# Keep the three newest releases.
find "$base/releases" -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +4 | xargs -r rm -rf --

echo "Demo $target aktualisiert: $release_dir"
