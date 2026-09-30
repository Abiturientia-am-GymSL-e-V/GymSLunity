# GymSLunity installieren und betreiben

Diese Anleitung führt Schritt für Schritt durch eine Einzelserver-Installation mit Nginx, PHP-FPM und MariaDB (alternativ SQLite). Die Befehle sind für **Debian 13** geschrieben, das PHP 8.4 mitbringt. Unter **Ubuntu 24.04** ist PHP 8.3 der Standard, das nicht ausreicht. Dort PHP 8.4 vorher aus dem PPA `ondrej/php` einrichten (siehe Schritt 2).

In den Beispielen werden folgende Werte verwendet. Ersetze sie überall durch deine eigenen:

| Platzhalter           | Bedeutung                                                                                                      |
| --------------------- | -------------------------------------------------------------------------------------------------------------- |
| `verein.example.org`  | Domain, unter der GymSLunity erreichbar sein soll                                                              |
| `/var/www/gymslunity` | Installationsverzeichnis                                                                                       |
| `deploy`              | dein SSH-Benutzer, dem die Dateien gehören                                                                     |
| `www-data`            | Benutzer, unter dem PHP-FPM und Nginx laufen                                                                   |
| `1.0.0-beta.1`        | zu installierende Version (siehe [Releases](https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/releases)) |

Alle Einstellungen in der `.env` sind in [konfiguration.md](konfiguration.md) beschrieben.

## Automatische Installation

Das Skript [`scripts/install-server.sh`](../scripts/install-server.sh) erledigt die Schritte 1 bis 11 auf einem Server mit **Debian 12/13 oder Ubuntu 22.04/24.04** und installiert das neueste Release:

```bash
curl -fsSLO https://raw.githubusercontent.com/Abiturientia-am-GymSL-e-V/GymSLunity/main/scripts/install-server.sh
sudo bash install-server.sh
```

Es fragt nach Domain, Installationsverzeichnis, HTTPS-Variante (Let's Encrypt, vorhandenes Zertifikat oder vorgeschalteter Reverse Proxy), Datenbank (MariaDB oder SQLite), Vereinsname und Absenderadresse, Backup-Verzeichnis und dem ersten Administratorkonto. Danach:

1. prüft es, dass nichts Bestehendes überschrieben würde: Benutzer, Verzeichnisse, Datenbank, Nginx-Sites mit derselben Domain, belegte Ports 80/443, eine fehlerhafte Nginx-Konfiguration,
2. installiert es fehlende Pakete (Nginx, Certbot, PHP 8.4 mit Erweiterungen, bei Bedarf MariaDB). Ein vorhandenes anderes PHP bleibt Standard; fehlt PHP 8.4 in den Paketquellen, fragt es vor dem Hinzufügen von `ppa:ondrej/php` bzw. `packages.sury.org`. Vorhandene Pakete werden nicht aktualisiert,
3. legt einen eigenen Systembenutzer mit eigenem PHP-FPM-Pool an. Der Code gehört root; PHP darf nur `storage/`, `bootstrap/cache/` und das Backup-Verzeichnis beschreiben, die `.env` nur lesen. Nginx sieht nur `public/`,
4. legt Datenbank und Datenbankbenutzer mit zufälligem Passwort an (MariaDB) bzw. die Datenbankdatei unter `storage/database/` (SQLite), erzeugt die `.env` aus `.env.example` mit zufälligem `APP_KEY` und `PASSKEYS_USER_HANDLE_SECRET` und führt `app:install` mit dem Administratorkonto aus. Der Browser-Installer ist danach gesperrt,
5. richtet Nginx mit HTTPS sowie Queue-Worker und Scheduler als systemd-Dienste (`<name>-queue`, `<name>-schedule.timer`) ein und prüft zum Schluss `/up`, die Anmeldeseite und `security:check`.

Nach dem Skript fehlt nur noch der E-Mail-Versand (Schritt 10). Sichere außerdem die `.env` an einem zweiten Ort und kopiere die Backups regelmäßig auf ein anderes System (Schritt 12).

Schlägt ein Schritt fehl oder wird das Skript mit Strg+C abgebrochen, macht es alle bisherigen Änderungen rückgängig, einschließlich der dabei installierten Pakete. `sudo bash install-server.sh --uninstall` entfernt die Instanz später wieder. Datenbank, Dateien, `.env` und Backups bleiben dabei erhalten, außer ihre Löschung wird ausdrücklich bestätigt; dann legt das Skript vorher ein letztes Backup unter `/root/` ab. `--help` listet die Umgebungsvariablen für unbeaufsichtigte Installationen (`--yes`).

Updates bleiben Handarbeit, siehe [Abschnitt 13](#13-updates). Die übrigen Abschnitte beschreiben die Installation von Hand.

## 1. Voraussetzungen

- Linux-Server mit Root- oder sudo-Zugang und einer Domain, deren DNS-Eintrag auf den Server zeigt
- PHP 8.4.1 oder neuer mit den Erweiterungen `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `intl`, `mbstring`, `openssl`, `pcre`, `pdo`, `session`, `tokenizer`, `xml`, `zip` und einem PDO-Treiber (`pdo_mysql` oder `pdo_sqlite`)
- MariaDB/MySQL oder SQLite
- Nginx (oder ein anderer Webserver, der nur `public/` ausliefert)
- nur bei Installation per Git: Composer 2 sowie Node.js 24 und npm
- optional Redis mit der PHP-Erweiterung `redis`

Der Webserver darf ausschließlich das Verzeichnis `public/` ausliefern. `.env`, Quelltext, `vendor/`, `storage/` und Backups dürfen nicht direkt erreichbar sein.

## 2. Serverpakete installieren

Nur unter Ubuntu 24.04 zuerst PHP 8.4 verfügbar machen:

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
```

```bash
sudo apt update
sudo apt install -y nginx mariadb-server certbot unzip curl \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-sqlite3 php8.4-mbstring \
    php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl
```

Für SQLite statt MariaDB kann `mariadb-server` entfallen. `mariadb-server` bringt auch `mariadb-dump` mit, das für Backups benötigt wird.

Nur für die Installation per Git zusätzlich Composer und Node.js 24:

```bash
sudo apt install -y composer git
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt install -y nodejs
```

Prüfen:

```bash
php -v
php -m | grep -E -i 'gd|intl|mbstring|pdo_mysql|pdo_sqlite|zip'
```

## 3. GymSLunity herunterladen

Wähle **eine** der beiden Varianten.

### Variante A: Release-Archiv (empfohlen)

Das Archiv enthält bereits `vendor/` und das gebaute Frontend (`public/build/`). Composer und Node.js werden auf dem Server nicht benötigt.

```bash
VERSION=1.0.0-beta.1
cd /tmp
curl -fLO https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/releases/download/v$VERSION/gymslunity-v$VERSION.tar.gz
curl -fLO https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/releases/download/v$VERSION/gymslunity-v$VERSION.tar.gz.sha256
sha256sum -c gymslunity-v$VERSION.tar.gz.sha256

sudo mkdir -p /var/www
sudo tar -xzf gymslunity-v$VERSION.tar.gz -C /var/www
sudo mv /var/www/gymslunity-v$VERSION /var/www/gymslunity
sudo chown -R deploy:www-data /var/www/gymslunity
```

`sha256sum -c` muss `OK` melden. Andernfalls das Archiv nicht verwenden.

### Variante B: Git

```bash
sudo mkdir -p /var/www/gymslunity
sudo chown deploy:www-data /var/www/gymslunity
git clone https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity.git /var/www/gymslunity
cd /var/www/gymslunity
git checkout v1.0.0-beta.1
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Führe Composer und npm als `deploy` aus, nicht als Root und nicht als `www-data`.

Alle `php artisan`-Befehle in dieser Anleitung laufen dagegen als `www-data`, damit Log- und Cache-Dateien dem PHP-FPM-Benutzer gehören.

## 4. Datenbank anlegen

### MariaDB

Erzeuge ein zufälliges Passwort und notiere es für Schritt 7:

```bash
openssl rand -base64 24
```

```bash
sudo mariadb
```

```sql
CREATE DATABASE gymslunity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gymslunity'@'localhost' IDENTIFIED BY 'DAS_ERZEUGTE_PASSWORT';
GRANT ALL PRIVILEGES ON gymslunity.* TO 'gymslunity'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### SQLite

Keine Einrichtung nötig. Das Release-Archiv enthält bereits eine leere `database/database.sqlite`, bei Git legt der Installer sie an. Das Verzeichnis `database/` braucht Schreibrecht für `www-data` (Schritt 5).

## 5. Dateirechte setzen

```bash
cd /var/www/gymslunity
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 0750 {} \;
sudo find . -type f -exec chmod 0640 {} \;
sudo chmod 0750 artisan
sudo chmod -R ug+rwX storage bootstrap/cache database
ln -s ../storage/app/public public/storage
```

Der Link `public/storage` macht hochgeladene öffentliche Dateien erreichbar. Er wird hier angelegt, weil `www-data` in `public/` nicht schreiben darf.

Für den Browser-Installer (Schritt 7, Variante A) braucht `www-data` **vorübergehend** Schreibrecht auf das Projektverzeichnis, um die `.env` anzulegen:

```bash
sudo chmod g+w /var/www/gymslunity
```

Gewähre niemals pauschal Schreibrechte für den gesamten Projektordner und verwende kein `chmod -R 777`.

## 6. Nginx und HTTPS einrichten

Zertifikat über die Standardseite von Nginx anfordern, die `/var/www/html` ausliefert:

```bash
sudo certbot certonly --webroot -w /var/www/html -d verein.example.org
```

Danach die GymSLunity-Site anlegen. Die Vorlage [nginx.conf.example](nginx.conf.example) liegt im Projekt:

```bash
sudo cp /var/www/gymslunity/docs/nginx.conf.example /etc/nginx/sites-available/gymslunity
sudo sed -i 's/verein\.example\.org/DEINE.DOMAIN/g' /etc/nginx/sites-available/gymslunity
sudo ln -s /etc/nginx/sites-available/gymslunity /etc/nginx/sites-enabled/gymslunity
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Prüfe in der kopierten Datei den PHP-FPM-Socket (`fastcgi_pass unix:/run/php/php8.4-fpm.sock;`). Die Vorlage leitet HTTP auf HTTPS um und liefert `/.well-known/acme-challenge/` weiter aus `/var/www/html` aus, damit Certbot das Zertifikat automatisch verlängern kann. Testen lässt sich die Verlängerung mit:

```bash
sudo certbot renew --dry-run
```

Läuft GymSLunity hinter einem weiteren Reverse Proxy oder Load Balancer, trage dessen Adresse später in der `.env` als `TRUSTED_PROXIES` ein. Sonst sehen Rate-Limits und Sicherheitsprotokoll nur die Adresse des Proxys.

## 7. Installation abschließen

Wähle **eine** der beiden Varianten.

### Variante A: im Browser

1. Öffne `https://verein.example.org/install`.
2. Der Installer fragt nach einem **Einrichtungscode**. Er wird beim ersten Aufruf erzeugt. Lies ihn auf dem Server aus:

    ```bash
    cat /var/www/gymslunity/storage/app/setup-token
    ```

3. Gib Adresse, Datenbankzugang und das erste Administratorkonto ein.

Der Installer legt die `.env` an, erzeugt zufällige Werte für `APP_KEY` und `PASSKEYS_USER_HANDLE_SECRET`, führt alle Migrationen aus und legt das Administratorkonto an. Danach ist er gesperrt. Entziehe anschließend das temporäre Schreibrecht und schütze die `.env`:

```bash
sudo chmod g-w /var/www/gymslunity
sudo chown deploy:www-data /var/www/gymslunity/.env
sudo chmod 0640 /var/www/gymslunity/.env
```

Der Installer übernimmt für einige Werte die Vorgaben der Vorlage. Setze danach in `/var/www/gymslunity/.env`:

```dotenv
LOG_LEVEL=warning
```

und übernimm die Änderung:

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan optimize
```

### Variante B: auf der Kommandozeile

```bash
cd /var/www/gymslunity
cp .env.example .env
chmod 0640 .env
nano .env
```

Setze mindestens diese Werte (Beschreibung aller Werte in [konfiguration.md](konfiguration.md)):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://verein.example.org
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
MAIL_FROM_ADDRESS="noreply@verein.example.org"
MAIL_FROM_NAME="Name des Vereins"
```

Für MariaDB zusätzlich (die `DB_`-Zeilen einkommentieren):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gymslunity
DB_USERNAME=gymslunity
DB_PASSWORD=DAS_ERZEUGTE_PASSWORT
```

Für SQLite bleibt `DB_CONNECTION=sqlite`.

Erzeuge zwei zufällige Schlüssel und trage sie in die `.env` als `APP_KEY` und `PASSKEYS_USER_HANDLE_SECRET` ein:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "PASSKEYS_USER_HANDLE_SECRET=base64:$(openssl rand -base64 32)"
```

Dann die Installation ausführen:

```bash
sudo -u www-data php artisan app:install
```

Die Installationsroutine:

1. prüft PHP-Version, Erweiterungen und Schreibrechte,
2. erzeugt bei Bedarf einen neuen `APP_KEY`,
3. prüft die konfigurierte Datenbankverbindung,
4. führt alle Migrationen aus,
5. legt den öffentlichen Storage-Link an,
6. fragt bei einer leeren Benutzerliste ein Administratorkonto ab und
7. erzeugt in der Produktivumgebung optimierte Laravel-Caches.

Für automatisierte Bereitstellung stehen `--force`, `--no-user`, `--skip-migrations` und `--skip-storage-link` zur Verfügung. Ohne interaktive Benutzeranlage kann später `php artisan app:create-user --role=admin` verwendet werden.

### Für beide Varianten

- Ändere oder entferne `APP_KEY` und `PASSKEYS_USER_HANDLE_SECRET` niemals bei einer bestehenden Installation. Verschlüsselte Daten (z. B. IBANs), signierte Backups und registrierte Passkeys wären danach unbrauchbar.
- Sichere die `.env` sofort an einem zweiten, geschützten Ort.
- Fehlt die `.env` später, bleibt der Installer trotzdem gesperrt (Markierung `storage/app/installed`). Stelle sie dann aus der Sicherung wieder her.

## 8. Queue-Worker einrichten

Bei `QUEUE_CONNECTION=database` (Standard) oder `redis` muss dauerhaft ein Worker laufen. Er versendet unter anderem E-Mails.

```bash
sudo tee /etc/systemd/system/gymslunity-queue.service > /dev/null <<'EOF'
[Unit]
Description=GymSLunity Queue Worker
After=network.target mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/gymslunity
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now gymslunity-queue.service
systemctl status gymslunity-queue.service
```

## 9. Geplante Aufgaben (Cron) einrichten

Der Laravel-Scheduler muss minütlich laufen. Er erstellt täglich um 02:30 Uhr ein Backup, bereinigt um 03:30 Uhr alte Sicherheitsdaten und rechnet alle 15 Minuten fällige Buchungen ab.

```bash
echo '* * * * * www-data cd /var/www/gymslunity && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' \
    | sudo tee /etc/cron.d/gymslunity > /dev/null
```

Prüfen, welche Aufgaben geplant sind:

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan schedule:list
```

## 10. E-Mail-Versand einrichten

Nach der Installation werden E-Mails nicht zugestellt, sondern nur ins Log geschrieben (`MAIL_MAILER=log`).

1. Melde dich mit dem Administratorkonto an.
2. Richte unter **Konfiguration → E-Mail-Versand** den SMTP-Server ein.
3. Sende dort eine Testmail.

Selfservice, Passwortzurücksetzung und Versandfunktionen erst danach produktiv verwenden. Alternativ kann der Transport auch über die `MAIL_`-Variablen in der `.env` gesetzt werden (siehe [konfiguration.md](konfiguration.md)).

## 11. Installation prüfen

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan security:check
curl -fsS https://verein.example.org/up
```

- `security:check` endet ohne Fehler. Die Prüfung auf einen echten Mailtransport schlägt fehl, solange Schritt 10 nicht erledigt ist.
- `/up` liefert einen erfolgreichen Status.
- Anmeldung mit dem Administratorkonto funktioniert.
- **Konfiguration → System** zeigt keine kritischen Produktionswarnungen.
- Die Testmail unter **Konfiguration → E-Mail-Versand** wird zugestellt.
- Queue-Worker (`systemctl status gymslunity-queue`) und Cron laufen.
- Ein Backup wurde erstellt und die Wiederherstellung getestet (Schritt 12).

GymSLunity sendet in Produktion über HTTPS einen HSTS-Header, der auch für Subdomains gilt. Wenn Subdomains deiner Domain noch ohne HTTPS erreichbar sein müssen, setze vorerst `SECURITY_HSTS_MAX_AGE=0`.

## 12. Backups

GymSLunity erzeugt täglich um 02:30 Uhr ein lokales Backup, sofern der Cron aus Schritt 9 läuft. Standardmäßig liegen die Archive mit restriktiven Dateirechten unter `storage/backups/` und werden nach 30 Tagen bereinigt. Ort und Frist lassen sich in der `.env` anpassen:

```dotenv
BACKUP_PATH=/var/backups/gymslunity
BACKUP_RETENTION_DAYS=30
```

Ein eigener Zielordner muss für `www-data` beschreibbar sein und darf nicht innerhalb von `storage/app/` liegen:

```bash
sudo mkdir -p /var/backups/gymslunity
sudo chown www-data:www-data /var/backups/gymslunity
sudo chmod 0700 /var/backups/gymslunity
```

Ein vollständiges Archiv enthält:

- die vollständige Datenbank,
- `.env` als `environment.env`,
- `storage/app/` als `storage-app/` und
- ein Manifest mit Erstellungszeitpunkt, Datenbanktreiber und Codeversion.

Ein Backup kann jederzeit manuell erzeugt werden:

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan app:backup --prune
```

Administratoren können unter **Konfiguration → System → Backup & Wiederherstellung** zusätzlich zwei gezielte Sicherungen herunterladen und wieder einspielen:

- Die Konfigurationssicherung im JSON-Format enthält Vereinsdaten, Mitgliedsfelder, E-Mail-Einstellungen und das Vereinslogo, aber keine Benutzer-, Mitglieder- oder Zahlungsdaten. Die Sicherung ist mit einem aus `APP_KEY` abgeleiteten Schlüssel signiert und lässt sich nur in eine Installation mit demselben `APP_KEY` einspielen, etwa nach einem Umzug mit übernommener `.env`. Veränderte oder fremde Dateien werden abgelehnt, ebenso unzulässige E-Mail-Einstellungen wie ein fremder Sendmail-Befehl.
- Die Datenbanksicherung im ZIP-Format enthält sämtliche Datenbanktabellen, jedoch weder `.env` noch Dateien aus `storage/app/`. Der Webimport akzeptiert nur Sicherungen derselben GymSLunity-Version, desselben Datenbanktreibers und derselben Installation (signiertes Manifest, gebunden an `APP_KEY`), prüft die SHA-256-Prüfsumme, importiert bei MariaDB/MySQL im Sandbox-Modus des Clients, aktiviert vorübergehend den Wartungsmodus und legt unmittelbar vorher ein lokales Datenbankbackup unter `BACKUP_PATH` an. Schlägt der Import fehl, wird diese Sicherheitssicherung automatisch eingespielt.

Für beide Importe muss zur Bestätigung `WIEDERHERSTELLEN` eingegeben werden; wie bei Exporten, der Benutzerverwaltung und den E-Mail-Einstellungen wird außerdem das Passwort erneut abgefragt, wenn die letzte Bestätigung länger als `SECURITY_RECONFIRM_SECONDS` (Standard 15 Minuten) zurückliegt. Die Zugriffe sind auf Administratoren beschränkt, gedrosselt und werden im Sicherheitsprotokoll erfasst. Die Browserfunktion ersetzt kein extern gespeichertes vollständiges Serverbackup.

Die ZIP-Dateien enthalten Schlüssel und personenbezogene Daten. Kopiere sie regelmäßig verschlüsselt auf ein anderes System. Ein lokales Backup allein schützt nicht vor dem Ausfall oder Verlust des Servers.

### Wiederherstellung testen

Teste die Wiederherstellung regelmäßig auf einem getrennten System. Verwende ein zur Anwendungsversion passendes Release, entpacke das Backup und kontrolliere zuerst `manifest.json`.

Für MariaDB/MySQL:

```bash
unzip gymslunity-JJJJMMTT-HHMMSS-XXXXXXXX.zip -d /tmp/gymslunity-restore
cd /var/www/gymslunity
sudo -u www-data php artisan down
mariadb -h DB_HOST -u DB_USERNAME -p DB_DATABASE < /tmp/gymslunity-restore/database.sql
rsync -a --delete /tmp/gymslunity-restore/storage-app/ storage/app/
cp /tmp/gymslunity-restore/environment.env .env
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan up
```

Für SQLite wird stattdessen bei gestoppter Anwendung `database.sqlite` an den in `DB_DATABASE` konfigurierten Ort kopiert. Setze nach dem Restore Eigentümer und Dateirechte erneut wie in Schritt 5. Führe eine Wiederherstellung niemals ungeprüft über eine laufende Produktivdatenbank aus.

## 13. Updates

Lies vor jedem Update die [Release-Notizen](releases/) der neuen Version. Sie nennen Schritte, die über den folgenden Ablauf hinausgehen.

### Release-Archiv

```bash
VERSION=1.0.1
cd /tmp
curl -fLO https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/releases/download/v$VERSION/gymslunity-v$VERSION.tar.gz
curl -fLO https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity/releases/download/v$VERSION/gymslunity-v$VERSION.tar.gz.sha256
sha256sum -c gymslunity-v$VERSION.tar.gz.sha256
tar -xzf gymslunity-v$VERSION.tar.gz

cd /var/www/gymslunity
sudo -u www-data php artisan app:backup --prune
sudo -u www-data php artisan down
rsync -a --delete \
    --exclude=.env \
    --exclude=storage/ \
    --exclude=bootstrap/cache/ \
    --exclude=database/database.sqlite \
    --exclude=public/storage \
    /tmp/gymslunity-v$VERSION/ /var/www/gymslunity/
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan up
```

`storage/` und `bootstrap/cache/` bleiben dabei unberührt, sodass die Dateirechte aus Schritt 5 erhalten bleiben. `optimize:clear` verwirft die Caches der alten Version.

#### Mit `install-server.sh` eingerichtete Instanz

Der Code gehört root, und PHP läuft als eigener Benutzer (Vorgabe `gymslunity`) mit PHP 8.4 unter `/usr/bin/php8.4`. Nach dem Herunterladen und Prüfen wie oben:

```bash
cd /var/www/gymslunity
sudo -u gymslunity php8.4 artisan app:backup --prune
sudo -u gymslunity php8.4 artisan down
sudo rsync -a --delete \
    --exclude=.env \
    --exclude=storage/ \
    --exclude=bootstrap/cache/ \
    --exclude=database/database.sqlite \
    --exclude=public/storage \
    /tmp/gymslunity-v$VERSION/ /var/www/gymslunity/
sudo -u gymslunity php8.4 artisan optimize:clear
sudo -u gymslunity php8.4 artisan migrate --force
sudo -u gymslunity php8.4 artisan optimize
sudo -u gymslunity php8.4 artisan queue:restart
sudo -u gymslunity php8.4 artisan up
```

Dateirechte müssen danach nicht gesetzt werden.

### Git

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan app:backup --prune
sudo -u www-data php artisan down
git fetch --tags
git checkout v1.0.1
composer install --no-dev --optimize-autoloader
npm ci
npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan up
```

**Konfiguration → System** zeigt an, ob eine neuere Version verfügbar ist (abschaltbar mit `GYMSLUNITY_UPDATE_CHECK=false`).

## 14. Redis (optional)

Redis ist keine Voraussetzung. Die Datenbanktreiber sind für eine einzelne, normal ausgelastete Vereinsinstanz einfacher zu betreiben und dauerhaft zu sichern.

Für höhere Last oder mehrere Anwendungsserver:

```bash
sudo apt install -y redis-server php8.4-redis
sudo systemctl restart php8.4-fpm
```

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_USERNAME=null
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1

CACHE_STORE=redis
SESSION_DRIVER=database
QUEUE_CONNECTION=redis
```

Redis darf nicht ungeschützt aus dem Internet erreichbar sein. Verwende Netzwerkfilter, lokale Bindung oder TLS sowie Authentifizierung entsprechend deiner Infrastruktur. Nutze getrennte Datenbanken beziehungsweise eindeutige Präfixe, wenn mehrere Anwendungen denselben Redis-Server verwenden. Sitzungen bleiben im Datenbanktreiber, damit Benutzer aktive Sitzungen einzeln einsehen und widerrufen können.

Danach:

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
```

## 15. Passkeys

Passkeys wie Touch ID, Face ID, Windows Hello oder Sicherheitsschlüssel basieren auf WebAuthn. Außer auf localhost funktionieren sie nur über HTTPS. `APP_URL` und gegebenenfalls `PASSKEYS_RELYING_PARTY_ID` sowie `PASSKEYS_ALLOWED_ORIGINS` müssen zur aufgerufenen Domain passen.

`PASSKEYS_USER_HANDLE_SECRET` gehört ins Backup und muss dauerhaft stabil bleiben.

Das technische Lösch- und Aufbewahrungskonzept ist in [`datenschutz-aufbewahrung.md`](datenschutz-aufbewahrung.md) beschrieben. `php artisan security:prune --dry-run` zeigt, welche technischen Datensätze die nächste tägliche Bereinigung betrifft.
