#!/usr/bin/env bash
# Erzeugt die Screenshots des Anwenderhandbuchs neu.
#
# Startet eine Wegwerf-Instanz mit eigener SQLite-Datenbank und eigenem
# storage-Verzeichnis im Demo-Modus, befüllt sie mit den Musterdaten und
# fotografiert sie mit scripts/handbuch-screenshots.mjs. Datenbank und
# Dateien der Entwicklungsumgebung bleiben unberührt.
#
#   scripts/handbuch-screenshots.sh [--only TEIL]
#
# Voraussetzungen: Entwicklungsumgebung nach docs/entwicklung.md (PHP, .env
# mit APP_KEY, vendor/), ein aktueller Frontend-Build (npm run build) und
# Playwright mit Chromium (npm i -g playwright && npx playwright install chromium).
set -euo pipefail

cd "$(dirname "$0")/.."
root=$(pwd)
port=${HANDBUCH_PORT:-8123}
tmp=$(mktemp -d)
server=''

cleanup() {
    if [[ -n $server ]]; then
        kill "$server" 2>/dev/null || true
        wait "$server" 2>/dev/null || true
    fi
    rm -rf "$tmp"
}
trap cleanup EXIT

if [[ ! -f public/build/manifest.json ]]; then
    echo 'Kein Frontend-Build gefunden. Bitte zuerst npm run build ausführen.' >&2
    exit 1
fi

mkdir -p "$tmp"/storage/app/{private,public} "$tmp"/storage/framework/{cache/data,sessions,views} "$tmp"/storage/logs
touch "$tmp/database.sqlite" "$tmp/storage/app/installed"

export APP_ENV=local APP_DEBUG=false APP_URL="http://127.0.0.1:$port"
export DB_CONNECTION=sqlite DB_DATABASE="$tmp/database.sqlite" LARAVEL_STORAGE_PATH="$tmp/storage"
export CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync
export DEMO_MODE=true DEMO_PASSWORD=Demo-Passwort-2026

php artisan migrate --force --quiet
php artisan demo:reset --quiet

# artisan serve reicht die Umgebungsvariablen nicht an die Worker weiter.
(cd public && exec php -S "127.0.0.1:$port" "$root/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" > "$tmp/server.log" 2>&1) &
server=$!
for _ in $(seq 1 50); do
    curl -fs -o /dev/null "http://127.0.0.1:$port/login" && break
    sleep 0.2
done

node scripts/handbuch-screenshots.mjs --base "http://127.0.0.1:$port" --password "$DEMO_PASSWORD" "$@"

# Zweiter Durchgang mit einem Konto mit Authenticator-App für die Abfrage des
# zweiten Faktors. Es entsteht erst jetzt, damit es in Benutzerliste und
# Auditlog der übrigen Bilder nicht auftaucht.
php artisan tinker --execute "App\Models\User::factory()->withTwoFactor()->create(['name' => 'Zwei-Faktor-Beispiel', 'email' => 'zweifaktor@example.org', 'password' => Illuminate\Support\Facades\Hash::make(getenv('DEMO_PASSWORD')), 'roles' => ['bh']]);" > /dev/null
node scripts/handbuch-screenshots.mjs --base "http://127.0.0.1:$port" --password "$DEMO_PASSWORD" --after-setup "$@"

# Bildschirmfotos kommen mit 256 Farben ohne sichtbaren Verlust aus und
# werden dadurch etwa dreimal kleiner. Optional, falls Pillow installiert ist.
if python3 -c 'import PIL' 2>/dev/null; then
    python3 - <<'PY'
from pathlib import Path
from PIL import Image

for path in Path('docs/handbuch/bilder').glob('*.png'):
    image = Image.open(path)
    if image.mode != 'P':
        image.convert('RGB').quantize(256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE).save(path, optimize=True)
PY
else
    echo 'Hinweis: Ohne Pillow (pip install pillow) bleiben die Bilder unkomprimiert.' >&2
fi
