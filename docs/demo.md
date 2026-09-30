# Öffentliche Demo betreiben

Diese Anleitung richtet eine öffentliche Live-Demo mit zwei Instanzen ein:

| Instanz   | Beispiel-Domain                | Inhalt                              | Aktualisierung                                                         |
| --------- | ------------------------------ | ----------------------------------- | ---------------------------------------------------------------------- |
| `release` | `demo.gymslunity.example`      | neuestes Release, auch Pre-Releases | automatisch nach jedem Release (`release.yml`)                         |
| `next`    | `next.demo.gymslunity.example` | aktueller Stand von `main`          | automatisch nach jedem erfolgreichen Testlauf auf `main` (`tests.yml`) |

Beide Instanzen laufen mit `DEMO_MODE=true`. Was das bewirkt (Zugangsdaten auf der Anmeldeseite, Demo-Postfach statt E-Mail-Versand, nächtliche Zurücksetzung, gesperrte Funktionen), steht in [konfiguration.md](konfiguration.md#öffentliche-demo).

> [!CAUTION]
> Eine Demo-Instanz löscht bei jedem Deployment und jede Nacht alle Daten. Betreibe sie auf einem eigenen Server oder zumindest mit eigenem Benutzer, eigener Datenbank und eigener Domain, niemals neben einer Instanz mit echten Vereinsdaten.

## Automatische Einrichtung

Das Skript [`scripts/install-demo-server.sh`](../scripts/install-demo-server.sh) erledigt die Schritte 1 bis 4 und das erste Deployment auf einem Server mit **Debian 12/13 oder Ubuntu 22.04/24.04**:

```bash
curl -fsSLO https://raw.githubusercontent.com/Abiturientia-am-GymSL-e-V/GymSLunity/main/scripts/install-demo-server.sh
sudo bash install-demo-server.sh
```

Es fragt nach den Domains beider Instanzen, dem Installationsverzeichnis, der HTTPS-Variante (Let's Encrypt, vorhandenes Zertifikat oder vorgeschalteter Reverse Proxy), Impressum und Datenschutz des Betreibers und dem Demo-Passwort. Danach:

1. prüft es, dass nichts Bestehendes überschrieben würde: Benutzer, Verzeichnisse, Nginx-Sites mit denselben Domains, belegte Ports 80/443, eine fehlerhafte Nginx-Konfiguration,
2. installiert es fehlende Pakete (Nginx, Certbot, PHP 8.4 mit Erweiterungen). Ein vorhandenes anderes PHP bleibt Standard; fehlt PHP 8.4 in den Paketquellen, fragt es vor dem Hinzufügen von `ppa:ondrej/php` bzw. `packages.sury.org`. Vorhandene Pakete werden nicht aktualisiert,
3. legt Benutzer, Verzeichnisse, `.env`-Dateien, PHP-FPM-Pool, Nginx-Site und Zertifikat an. Nginx und PHP-FPM werden nur neu geladen, nicht neu gestartet,
4. stellt das neueste Release in beiden Instanzen bereit und startet Queue-Worker und Scheduler als systemd-Dienste (`<name>-queue@<instanz>`, `<name>-schedule@<instanz>.timer`) statt Cron,
5. richtet den Deploy-Schlüssel ein. Beschränkt der SSH-Server die Anmeldung mit `AllowUsers` oder `AllowGroups`, ergänzt es nach Rückfrage den Demo-Benutzer in `/etc/ssh/sshd_config.d/<name>.conf`; bestehende Einträge bleiben gültig. Am Ende gibt es die `gh`-Befehle aus, die Secrets und Variablen in GitHub setzen (Schritt 5). Sie laufen auf einem Rechner mit angemeldeter GitHub-CLI.

Schlägt ein Schritt fehl oder wird das Skript mit Strg+C abgebrochen, macht es alle bisherigen Änderungen in umgekehrter Reihenfolge rückgängig, einschließlich der dabei installierten Pakete. Dasselbe Protokoll unter `/var/lib/gymslunity-demo-installer/` nutzt die Deinstallation:

```bash
sudo bash install-demo-server.sh --uninstall
```

Sie entfernt die Demo mit allen Daten und fragt, ob auch die installierten Pakete entfernt werden sollen. `--help` listet die Umgebungsvariablen, mit denen sich alle Fragen vorab beantworten lassen (zusammen mit `--yes` für unbeaufsichtigte Installationen). Der Workflow `demo-installer.yml` testet Installation, Rollback, SSH-Deployment und Deinstallation bei jeder Änderung an den Skripten.

Gegenüber der manuellen Einrichtung ist die Installation etwas strenger abgeschottet: Das Home-Verzeichnis und `.ssh/authorized_keys` gehören root, damit PHP die Beschränkung des Deploy-Schlüssels nicht aufheben kann. `.env`, Datenbank und Sitzungen sind für Nginx nicht lesbar. Die systemd-Dienste dürfen nur im Installationsverzeichnis schreiben.

## Manuelle Einrichtung

Die folgenden Schritte beschreiben dieselbe Einrichtung von Hand.

## 1. Server vorbereiten

Installiere PHP 8.4, Nginx und die übrigen Pakete wie in [installation.md](installation.md), Schritte 1 und 2. Als Datenbank genügt SQLite. Node, Composer und Git sind auf dem Server nicht nötig, weil GitHub Actions fertige Release-Archive liefert.

Lege einen eigenen, nicht privilegierten Benutzer und die Verzeichnisse an:

```bash
sudo adduser --system --group --home /srv/gymslunity-demo --shell /bin/bash gymslunity-demo

for target in release next; do
    sudo -u gymslunity-demo mkdir -p \
        /srv/gymslunity-demo/$target/releases \
        /srv/gymslunity-demo/$target/shared/storage/app/private \
        /srv/gymslunity-demo/$target/shared/storage/app/public \
        /srv/gymslunity-demo/$target/shared/storage/framework/cache/data \
        /srv/gymslunity-demo/$target/shared/storage/framework/sessions \
        /srv/gymslunity-demo/$target/shared/storage/framework/views \
        /srv/gymslunity-demo/$target/shared/storage/logs
    sudo -u gymslunity-demo touch /srv/gymslunity-demo/$target/shared/database.sqlite
    # Hält den Browser-Installer geschlossen; die Zurücksetzung löscht diese Datei nicht.
    sudo -u gymslunity-demo touch /srv/gymslunity-demo/$target/shared/storage/app/installed
done
```

Installiere das Deploy-Skript aus dem Repository (oder einem Release-Archiv) so, dass der Demo-Benutzer es nicht verändern kann:

```bash
sudo install -o root -g root -m 0755 scripts/deploy-demo.sh /usr/local/bin/gymslunity-demo-deploy
```

## 2. `.env` je Instanz

Lege für jede Instanz `/srv/gymslunity-demo/<instanz>/shared/.env` an, am Beispiel `next`:

```bash
sudo -u gymslunity-demo tee /srv/gymslunity-demo/next/shared/.env > /dev/null <<EOF
APP_NAME=GymSLunity
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=https://next.demo.gymslunity.example
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true

DB_CONNECTION=sqlite
DB_DATABASE=/srv/gymslunity-demo/next/shared/database.sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_FROM_ADDRESS="noreply@demo.gymslunity.example"

DEMO_MODE=true
DEMO_PASSWORD=Demo-Passwort-2026
DEMO_RESET_AT=00:00
DEMO_IMPRINT_URL=https://betreiber.example/impressum
DEMO_PRIVACY_URL=https://betreiber.example/datenschutz
EOF
sudo chmod 0640 /srv/gymslunity-demo/next/shared/.env
```

Für `release` entsprechend mit eigener Domain, eigenem Datenbankpfad und eigenem `APP_KEY`. Das Deploy-Skript bricht ab, wenn die Zeile `DEMO_MODE=true` fehlt. Beschreibung aller Werte: [konfiguration.md](konfiguration.md).

`DEMO_IMPRINT_URL` und `DEMO_PRIVACY_URL` sollten auf Impressum und Datenschutzerklärung des Betreibers zeigen. Ohne sie zeigt die Demo die Seiten des fiktiven Mustervereins.

## 3. PHP-FPM und Nginx

Ein eigener FPM-Pool lässt PHP als Demo-Benutzer laufen:

```bash
sudo tee /etc/php/8.4/fpm/pool.d/gymslunity-demo.conf > /dev/null <<'EOF'
[gymslunity-demo]
user = gymslunity-demo
group = gymslunity-demo
listen = /run/php/gymslunity-demo.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
EOF
sudo systemctl reload php8.4-fpm
```

Lege je Instanz einen Server-Block nach [nginx.conf.example](nginx.conf.example) an und ändere darin:

- `server_name` und die Zertifikatspfade auf die Domain der Instanz,
- `root /srv/gymslunity-demo/<instanz>/current/public;`,
- `fastcgi_pass unix:/run/php/gymslunity-demo.sock;`.

`current` ist ein Symlink, den jedes Deployment umstellt. Die Vorlage verwendet bereits `$realpath_root`, damit PHP nach dem Umstellen die neuen Dateien lädt.

## 4. Scheduler und Queue-Worker

Der Scheduler setzt die Demo nachts zurück:

```bash
sudo tee /etc/cron.d/gymslunity-demo > /dev/null <<'EOF'
* * * * * gymslunity-demo cd /srv/gymslunity-demo/release/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * gymslunity-demo cd /srv/gymslunity-demo/next/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
EOF
```

Ein Worker je Instanz verarbeitet die Warteschlange, zum Beispiel Mails ins Demo-Postfach:

```bash
sudo tee /etc/systemd/system/gymslunity-demo-queue@.service > /dev/null <<'EOF'
[Unit]
Description=GymSLunity Demo Queue Worker (%i)
After=network.target

[Service]
User=gymslunity-demo
Group=gymslunity-demo
WorkingDirectory=/srv/gymslunity-demo/%i/current
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
```

Die Worker erst nach dem ersten Deployment starten (Schritt 6), weil `current` vorher fehlt.

## 5. Deploy-Schlüssel und GitHub

Erzeuge einen eigenen Schlüssel nur für die Demo:

```bash
ssh-keygen -t ed25519 -N '' -C github-actions-demo -f demo_deploy
```

Trage den öffentlichen Schlüssel beim Demo-Benutzer ein. `command=` und `restrict` sorgen dafür, dass er ausschließlich das Deploy-Skript ausführen kann:

```bash
sudo -u gymslunity-demo mkdir -p -m 0700 /srv/gymslunity-demo/.ssh
echo "command=\"/usr/local/bin/gymslunity-demo-deploy\",restrict $(cat demo_deploy.pub)" \
    | sudo -u gymslunity-demo tee -a /srv/gymslunity-demo/.ssh/authorized_keys > /dev/null
sudo chmod 0600 /srv/gymslunity-demo/.ssh/authorized_keys
```

In GitHub unter **Settings → Environments** eine Umgebung `demo` anlegen und dort als **Secrets** hinterlegen:

| Secret                 | Inhalt                                                       |
| ---------------------- | ------------------------------------------------------------ |
| `DEMO_SSH_KEY`         | Inhalt der privaten Schlüsseldatei `demo_deploy`             |
| `DEMO_SSH_KNOWN_HOSTS` | Ausgabe von `ssh-keyscan -t ed25519 demo.gymslunity.example` |

Unter **Settings → Secrets and variables → Actions → Variables** als Repository-Variablen:

| Variable          | Inhalt                                            |
| ----------------- | ------------------------------------------------- |
| `DEMO_SSH_TARGET` | `gymslunity-demo@demo.gymslunity.example`         |
| `DEMO_DEPLOY`     | `true` schaltet die automatischen Deployments ein |

Lösche danach die lokale Datei `demo_deploy`. Wer das Secret kennt, kann beliebigen Code als Demo-Benutzer ausführen; deshalb darf dieser Benutzer keine weiteren Rechte haben. Optional lässt sich die Umgebung `demo` unter **Deployment branches and tags** auf `main` und die Tags `v*` beschränken.

## 6. Erstes Deployment

Unter **Actions → Demo-Deployment → Run workflow** einmal `release` und einmal `next` starten. Danach die Worker starten:

```bash
sudo systemctl enable --now gymslunity-demo-queue@release gymslunity-demo-queue@next
```

Ab jetzt aktualisieren sich beide Instanzen automatisch. Jedes Deployment:

1. entpackt das Archiv nach `releases/<Zeitstempel>` und verknüpft `.env` und `storage` aus `shared/`,
2. setzt die Datenbank mit `php artisan demo:reset` auf die Musterdaten zurück,
3. stellt `current` auf die neue Version um und startet die Worker neu,
4. behält die drei neuesten Versionen.

## Betrieb

- **Manuell zurücksetzen:** `sudo -u gymslunity-demo php /srv/gymslunity-demo/next/current/artisan demo:reset`
- **Geplante Aufgaben prüfen:** `sudo -u gymslunity-demo php /srv/gymslunity-demo/next/current/artisan schedule:list`
- **Logs:** `/srv/gymslunity-demo/<instanz>/shared/storage/logs/`
- **Deployment von Hand** (etwa zum Testen eines Archivs): `ssh gymslunity-demo@demo.gymslunity.example next < gymslunity-v1.0.0.tar.gz`
- Die Zugangsdaten stehen auf der Anmeldeseite, E-Mails im Demo-Postfach unter `/demo/postfach`.
