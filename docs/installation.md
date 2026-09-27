# GymSLunity installieren und betreiben

Diese Anleitung beschreibt eine klassische Einzelserver-Installation mit Nginx, PHP-FPM und MariaDB. Passe Paketnamen, PHP-FPM-Socket und Benutzer an deine Distribution an.

## 1. Voraussetzungen

- Linux-Server mit HTTPS-fähigem Webserver
- PHP 8.3 oder neuer mit `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo`, einem passenden PDO-Datenbanktreiber, `session`, `tokenizer`, `xml` und `zip`
- Composer 2
- Node.js 24 und npm für den einmaligen Frontend-Build
- MariaDB/MySQL oder SQLite
- optional Redis mit der PHP-Erweiterung `redis`
- ein Prozessmanager für `queue:work`, zum Beispiel systemd oder Supervisor

Der Webserver darf ausschließlich das Verzeichnis `public/` ausliefern. `.env`, Quelltext, `vendor/`, `storage/` und Backups dürfen nicht direkt erreichbar sein.

## 2. Release-Archiv oder Git

Das bei GitHub angehängte Release-Archiv enthält vendor/ und public/build/. Auf dem Zielserver werden daher weder Composer noch Node.js benötigt. Prüfe die mitgelieferte SHA-256-Datei, entpacke das Archiv nach /var/www und benenne den enthaltenen Versionsordner in gymslunity um.

Für eine Git-Installation verwendest du den folgenden Ablauf.

```bash
git clone https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity.git /var/www/gymslunity
cd /var/www/gymslunity
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Führe Composer nicht als Webserver-Benutzer aus. Der PHP-FPM-Prozess benötigt Leserechte auf den Anwendungscode und Schreibrechte ausschließlich auf `storage/` und `bootstrap/cache/`.

## 3. Datenbank vorbereiten

Beispiel für MariaDB:

```sql
CREATE DATABASE gymslunity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gymslunity'@'localhost' IDENTIFIED BY 'EIN_LANGES_ZUFAELLIGES_PASSWORT';
GRANT ALL PRIVILEGES ON gymslunity.* TO 'gymslunity'@'localhost';
FLUSH PRIVILEGES;
```

Trage anschließend die Verbindung in `.env` ein:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gymslunity
DB_USERNAME=gymslunity
DB_PASSWORD=EIN_LANGES_ZUFAELLIGES_PASSWORT
```

Für SQLite muss `pdo_sqlite` installiert sein:

```bash
touch database/database.sqlite
```

```dotenv
DB_CONNECTION=sqlite
```

## 4. Browser-Installation

Setze zuerst die Dateirechte wie in Abschnitt 7. Der PHP-FPM-Benutzer benötigt vorübergehend Schreibrecht auf /var/www/gymslunity und bei SQLite zusätzlich auf database/. Richte dann Nginx und HTTPS ein und öffne https://verein.example.org/install.

Der Installer erzeugt zufällige Anwendungs- und Passkey-Schlüssel, prüft die Datenbank, führt alle Migrationen aus und legt das erste Administratorkonto an. Sobald ein Benutzer existiert, ist er gesperrt. Entziehe nach erfolgreicher Installation das temporäre Schreibrecht am Projektverzeichnis.

Für die manuelle Installation kopierst du .env.example nach .env, konfigurierst die folgenden Werte und führst später php artisan app:install aus.

## 5. Anwendung manuell konfigurieren

Mindestens diese Werte prüfen:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://verein.example.org
APP_DISPLAY_TIMEZONE=Europe/Berlin

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=log
```

`MAIL_MAILER=log` stellt keine E-Mails zu. Nach der ersten Anmeldung kann ein Administrator den Mailtransport unter **Konfiguration → E-Mail-Versand** einrichten und testen. Selfservice, Passwortzurücksetzung und Versandfunktionen erst danach produktiv verwenden.

Ändere oder entferne einen vorhandenen `APP_KEY` niemals bei einer bestehenden Installation. Damit verschlüsselte Daten wären anschließend nicht mehr lesbar.

## 6. Installation auf der Kommandozeile abschließen

```bash
npm ci
npm run build
php artisan app:install
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

## 7. Dateirechte

Beispiel mit dem PHP-FPM-Benutzer `www-data`:

```bash
sudo chown -R deploy:www-data /var/www/gymslunity
sudo find /var/www/gymslunity -type d -exec chmod 0750 {} \;
sudo find /var/www/gymslunity -type f -exec chmod 0640 {} \;
sudo chmod -R ug+rwX /var/www/gymslunity/storage /var/www/gymslunity/bootstrap/cache
sudo chmod ug+rwX /var/www/gymslunity/database
sudo chmod 0640 /var/www/gymslunity/.env
```

Der tatsächliche Benutzer und die Gruppenstrategie hängen vom Server ab. Gewähre niemals pauschal Schreibrechte für den gesamten Projektordner und verwende kein `chmod -R 777`.

## 8. Nginx und HTTPS

Kopiere [nginx.conf.example](nginx.conf.example) als Ausgangspunkt in die Nginx-Konfiguration, ersetze Domain und PHP-FPM-Socket und aktiviere die Site. Richte anschließend ein gültiges TLS-Zertifikat sowie die HTTP-zu-HTTPS-Weiterleitung ein.

```bash
sudo nginx -t
sudo systemctl reload nginx
```

HSTS erst aktivieren, wenn die Domain einschließlich aller benötigten Subdomains dauerhaft ausschließlich per HTTPS erreichbar ist.

## 9. Queue-Worker

Bei `QUEUE_CONNECTION=database` oder `redis` muss dauerhaft ein Worker laufen:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Beispiel für systemd:

```ini
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
```

Nach dem Anlegen:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now gymslunity-queue.service
```

## 10. Redis optional aktivieren

Redis ist keine Voraussetzung. Die Datenbanktreiber sind für eine einzelne, normal ausgelastete Vereinsinstanz einfacher zu betreiben und dauerhaft zu sichern.

Für höhere Last oder mehrere Anwendungsserver:

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
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
```

## 11. Cron und regelmäßige Aufgaben

Führe den Laravel-Scheduler minütlich aus:

```cron
* * * * * cd /var/www/gymslunity && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 12. Backups und Updates

GymSLunity erzeugt täglich um 02:30 Uhr ein lokales Backup, sofern der in Abschnitt 11 beschriebene Scheduler läuft. Standardmäßig liegen die Archive mit restriktiven Dateirechten unter `storage/backups/` und werden nach 30 Tagen bereinigt. Passe Ort und Frist bei Bedarf in `.env` an:

```dotenv
BACKUP_PATH=/var/backups/gymslunity
BACKUP_RETENTION_DAYS=30
```

Der Zielordner darf nicht innerhalb von `storage/app/` liegen. Ein vollständiges Archiv enthält:

- die vollständige Datenbank,
- `.env` als `environment.env`,
- `storage/app/` als `storage-app/` und
- ein Manifest mit Erstellungszeitpunkt, Datenbanktreiber und Codeversion.

Ein Backup kann jederzeit manuell erzeugt werden:

```bash
php artisan app:backup --prune
```

Administratoren können unter **Konfiguration → System → Backup & Wiederherstellung** zusätzlich zwei gezielte Sicherungen herunterladen und wieder einspielen:

- Die Konfigurationssicherung im JSON-Format enthält Vereinsdaten, Mitgliedsfelder, E-Mail-Einstellungen und das Vereinslogo, aber keine Benutzer-, Mitglieder- oder Zahlungsdaten. Das SMTP-Passwort bleibt mit dem aktuellen `APP_KEY` verschlüsselt; für einen Umzug auf eine Installation mit anderem Schlüssel muss es anschließend neu gesetzt werden.
- Die Datenbanksicherung im ZIP-Format enthält sämtliche Datenbanktabellen, jedoch weder `.env` noch Dateien aus `storage/app/`. Der Webimport akzeptiert nur Sicherungen derselben GymSLunity-Version und desselben Datenbanktreibers, prüft die SHA-256-Prüfsumme, aktiviert vorübergehend den Wartungsmodus und legt unmittelbar vorher ein lokales Datenbankbackup unter `BACKUP_PATH` an. Schlägt der Import fehl, wird diese Sicherheitssicherung automatisch eingespielt.

Für beide Importe muss zur Bestätigung `WIEDERHERSTELLEN` eingegeben werden. Die Zugriffe sind auf Administratoren beschränkt, gedrosselt und werden im Sicherheitsprotokoll erfasst. Die Browserfunktion ersetzt kein extern gespeichertes vollständiges Serverbackup.

Für MariaDB/MySQL muss `mariadb-dump` oder `mysqldump` installiert sein. Die ZIP-Dateien enthalten Schlüssel und personenbezogene Daten. Sichere sie mit restriktiven Rechten und kopiere sie regelmäßig verschlüsselt auf ein anderes System. Ein lokales Backup allein schützt nicht vor dem Ausfall oder Verlust des Servers.

### Wiederherstellung testen

Teste die Wiederherstellung regelmäßig auf einem getrennten System. Verwende ein zur Anwendungsversion passendes Release, entpacke das Backup und kontrolliere zuerst `manifest.json`.

Für MariaDB/MySQL:

```bash
unzip gymslunity-JJJJMMTT-HHMMSS-XXXXXXXX.zip -d /tmp/gymslunity-restore
php artisan down
mariadb -h DB_HOST -u DB_USERNAME -p DB_DATABASE < /tmp/gymslunity-restore/database.sql
rsync -a --delete /tmp/gymslunity-restore/storage-app/ storage/app/
cp /tmp/gymslunity-restore/environment.env .env
php artisan optimize:clear
php artisan migrate --force
php artisan up
```

Für SQLite wird stattdessen bei gestoppter Anwendung `database.sqlite` an den in `DB_DATABASE` konfigurierten Ort kopiert. Setze nach dem Restore Eigentümer und Dateirechte erneut passend zum PHP-FPM-Benutzer. Führe eine Wiederherstellung niemals ungeprüft über eine laufende Produktivdatenbank aus.

Vor Updates zuerst ein frisches Backup erzeugen und danach:

```bash
php artisan app:backup --prune
php artisan down
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

## 13. Passkeys

Passkeys wie Touch ID, Face ID, Windows Hello oder Sicherheitsschlüssel basieren auf WebAuthn. Außer auf localhost funktionieren sie nur über HTTPS. APP_URL und gegebenenfalls PASSKEYS_RELYING_PARTY_ID sowie PASSKEYS_ALLOWED_ORIGINS müssen zur aufgerufenen Domain passen.

Der Browser-Installer erzeugt einen unabhängigen PASSKEYS_USER_HANDLE_SECRET. Dieser Wert gehört ins Backup und muss dauerhaft stabil bleiben.

## 14. Kontrolle

- `php artisan security:check` endet ohne Fehler.
- `https://verein.example.org/up` liefert einen erfolgreichen Status.
- Anmeldung mit dem Administratorkonto funktioniert.
- **Konfiguration → System** zeigt keine kritischen Produktionswarnungen.
- Testmail unter **Konfiguration → E-Mail-Versand** wird zugestellt.
- Queue-Worker und Cron laufen.
- Backup und Wiederherstellung wurden getestet.

Das technische Lösch- und Aufbewahrungskonzept ist in [`datenschutz-aufbewahrung.md`](datenschutz-aufbewahrung.md) beschrieben. `php artisan security:prune --dry-run` zeigt, welche technischen Datensätze die nächste tägliche Bereinigung betrifft.
