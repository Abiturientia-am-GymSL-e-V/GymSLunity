# Konfiguration über die `.env`

GymSLunity liest seine Serverkonfiguration aus der Datei `.env` im Installationsverzeichnis. Vorlage ist [`.env.example`](../.env.example). Sie enthält Werte für die lokale Entwicklung. Wie du daraus eine Produktivkonfiguration machst, beschreibt die [Installationsanleitung](installation.md).

Die meisten fachlichen Einstellungen (Vereinsdaten, Mitgliedsfelder, E-Mail-Versand, Module) werden nicht hier, sondern in der Oberfläche unter **Konfiguration** gepflegt.

Nach jeder Änderung an der `.env` die Caches neu aufbauen und den Queue-Worker neu starten:

```bash
cd /var/www/gymslunity
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
```

`php artisan security:check` prüft die wichtigsten Produktivwerte.

In der Spalte **Produktion** steht der empfohlene Wert, wenn er von der Vorlage abweicht. **Pflicht** heißt, dass `security:check` sonst einen Fehler meldet.

## Anwendung

| Variable                  | Vorlage                 | Produktion                | Bedeutung                                                                                                                                                                                |
| ------------------------- | ----------------------- | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                 | `local`                 | `production` (Pflicht)    | Umgebung. `local` nur für die Entwicklung.                                                                                                                                               |
| `APP_KEY`                 | leer                    | zufällig (Pflicht)        | Hauptschlüssel für Verschlüsselung und Signaturen. Wird vom Installer erzeugt. **Niemals ändern**: IBANs, verschlüsselte Sitzungen und signierte Backups wären danach nicht mehr lesbar. |
| `APP_PREVIOUS_KEYS`       | –                       | –                         | Frühere Schlüssel, durch Komma getrennt, falls `APP_KEY` doch einmal rotiert wurde.                                                                                                      |
| `APP_DEBUG`               | `true`                  | `false` (Pflicht)         | Zeigt Fehlerdetails samt Konfiguration im Browser.                                                                                                                                       |
| `APP_URL`                 | `http://localhost:8000` | `https://…` (Pflicht)     | Öffentliche Adresse ohne abschließenden Schrägstrich. Grundlage für Links in E-Mails und für Passkeys.                                                                                   |
| `APP_DISPLAY_TIMEZONE`    | `Europe/Berlin`         |                           | Zeitzone für Anzeige, Belegdatum und Nummernjahr.                                                                                                                                        |
| `GYMSLUNITY_UPDATE_CHECK` | `true`                  |                           | Fragt die GitHub-Releases ab und zeigt unter **Konfiguration → System** neuere Versionen an.                                                                                             |
| `APP_LOCALE`              | `de`                    |                           | Sprache der Oberfläche.                                                                                                                                                                  |
| `APP_FALLBACK_LOCALE`     | `en`                    |                           | Sprache für fehlende Übersetzungen.                                                                                                                                                      |
| `APP_FAKER_LOCALE`        | `de_DE`                 |                           | Nur für Testdaten.                                                                                                                                                                       |
| `APP_MAINTENANCE_DRIVER`  | `file`                  |                           | Speichert den Wartungsmodus (`php artisan down`). Bei mehreren Servern `cache`.                                                                                                          |
| `APP_MAINTENANCE_STORE`   | `database`              |                           | Cache für den Wartungsmodus, wenn der Treiber `cache` ist.                                                                                                                               |
| `BCRYPT_ROUNDS`           | `12`                    | mindestens `12` (Pflicht) | Rechenaufwand für Passwort-Hashes.                                                                                                                                                       |

## Passkeys

| Variable                      | Vorlage            | Produktion | Bedeutung                                                                                                                                                                                                  |
| ----------------------------- | ------------------ | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PASSKEYS_USER_HANDLE_SECRET` | leer               | zufällig   | Schlüssel für die Benutzerkennung in Passkeys. Wird vom Installer erzeugt. Leer wird er aus `APP_KEY` abgeleitet. **Muss dauerhaft gleich bleiben**, sonst werden alle registrierten Passkeys unbrauchbar. |
| `PASSKEYS_RELYING_PARTY_ID`   | Host aus `APP_URL` |            | Domain, an die Passkeys gebunden sind. Nur setzen, wenn sie von `APP_URL` abweicht.                                                                                                                        |
| `PASSKEYS_ALLOWED_ORIGINS`    | `APP_URL`          |            | Erlaubte Origins, durch Komma getrennt.                                                                                                                                                                    |

Passkeys funktionieren außerhalb von localhost nur über HTTPS.

## Sitzungen und Sicherheit

| Variable                                   | Vorlage                      | Produktion           | Bedeutung                                                                                                                                                                                                                |
| ------------------------------------------ | ---------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `SESSION_DRIVER`                           | `database`                   | `database` (Pflicht) | Nur mit `database` können Benutzer aktive Sitzungen einzeln widerrufen.                                                                                                                                                  |
| `SESSION_LIFETIME`                         | `30`                         |                      | Sitzungsdauer in Minuten. Darf `SECURITY_INACTIVITY_TIMEOUT` nicht überschreiten (Pflicht).                                                                                                                              |
| `SESSION_ENCRYPT`                          | `true`                       | `true` (Pflicht)     | Verschlüsselt Sitzungsdaten.                                                                                                                                                                                             |
| `SESSION_SECURE_COOKIE`                    | `false`                      | `true` (Pflicht)     | Sitzungscookie nur über HTTPS senden.                                                                                                                                                                                    |
| `SESSION_PATH`                             | `/`                          |                      | Cookie-Pfad.                                                                                                                                                                                                             |
| `SESSION_COOKIE`                           | `gymslunity_laravel_session` |                      | Name des Sitzungscookies.                                                                                                                                                                                                |
| `SESSION_DOMAIN`                           | `null`                       |                      | Cookie-Domain. `null` = nur die aufgerufene Domain.                                                                                                                                                                      |
| `SECURITY_INACTIVITY_TIMEOUT`              | `1800`                       |                      | Automatische Abmeldung nach Inaktivität, in Sekunden.                                                                                                                                                                    |
| `SECURITY_RECONFIRM_SECONDS`               | `900`                        |                      | Abstand in Sekunden, nach dem vor Exporten, Backups, Benutzerverwaltung und Mail-Einstellungen das Passwort erneut abgefragt wird.                                                                                       |
| `SECURITY_AUDIT_RETENTION_DAYS`            | `730`                        |                      | Aufbewahrung von Sicherheitsereignissen in Tagen.                                                                                                                                                                        |
| `SECURITY_AUDIT_IDENTIFIER_RETENTION_DAYS` | `90`                         |                      | Nach dieser Zahl von Tagen werden IP-Adressen und Browserkennungen aus Sicherheitsereignissen entfernt.                                                                                                                  |
| `SECURITY_HEADERS_ENABLED`                 | `true`                       | `true` (Pflicht)     | Setzt Content-Security-Policy und weitere Sicherheits-Header.                                                                                                                                                            |
| `SECURITY_HSTS_MAX_AGE`                    | `31536000`                   |                      | HSTS-Dauer in Sekunden. Der Header wird in Produktion über HTTPS automatisch gesendet und gilt auch für Subdomains. `0` hebt HSTS auf.                                                                                   |
| `TRUSTED_PROXIES`                          | –                            |                      | Adressen vorgeschalteter Reverse Proxies oder Load Balancer, durch Komma getrennt (`*` nur bei einem verwalteten Load Balancer). Ohne diesen Wert sehen Rate-Limits und Sicherheitsprotokoll nur die Adresse des Proxys. |

## Datenbank

| Variable        | Vorlage                    | Bedeutung                                               |
| --------------- | -------------------------- | ------------------------------------------------------- |
| `DB_CONNECTION` | `sqlite`                   | `sqlite`, `mysql` oder `mariadb`.                       |
| `DB_DATABASE`   | `database/database.sqlite` | Bei SQLite der Pfad zur Datei, sonst der Datenbankname. |
| `DB_HOST`       | `127.0.0.1`                | Datenbankserver (nur MariaDB/MySQL).                    |
| `DB_PORT`       | `3306`                     | Port (nur MariaDB/MySQL).                               |
| `DB_USERNAME`   | –                          | Datenbankbenutzer (nur MariaDB/MySQL).                  |
| `DB_PASSWORD`   | –                          | Passwort (nur MariaDB/MySQL).                           |
| `DB_SOCKET`     | –                          | Unix-Socket statt Host und Port.                        |
| `DB_URL`        | –                          | Alternativ die gesamte Verbindung als URL.              |

## Hintergrunddienste und Cache

| Variable               | Vorlage           | Bedeutung                                                                                                                                                                                                                           |
| ---------------------- | ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `QUEUE_CONNECTION`     | `database`        | Warteschlange für Hintergrundaufgaben wie den E-Mail-Versand: `database` oder `redis`. Beide brauchen einen dauerhaft laufenden Queue-Worker. `sync` führt Aufgaben sofort im Webrequest aus und ist für Produktion nicht geeignet. |
| `CACHE_STORE`          | `database`        | Cache für Rate-Limits und Einstellungen: `database`, `file` oder `redis`. Muss dauerhaft sein (nicht `array` oder `null`, Pflicht).                                                                                                 |
| `CACHE_PREFIX`         | aus dem App-Namen | Präfix, wenn sich mehrere Anwendungen einen Cache teilen.                                                                                                                                                                           |
| `FILESYSTEM_DISK`      | `local`           | Speicherort für Dateien (`storage/app/`).                                                                                                                                                                                           |
| `BROADCAST_CONNECTION` | `log`             | Wird von GymSLunity nicht genutzt.                                                                                                                                                                                                  |

## Backups

| Variable                | Vorlage           | Bedeutung                                                                                                                       |
| ----------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `BACKUP_PATH`           | `storage/backups` | Zielordner für die täglichen Backups. Muss für `www-data` beschreibbar sein und darf nicht innerhalb von `storage/app/` liegen. |
| `BACKUP_RETENTION_DAYS` | `30`              | Ältere Backups werden automatisch gelöscht.                                                                                     |

## Protokollierung

| Variable                   | Vorlage  | Produktion | Bedeutung                                                                            |
| -------------------------- | -------- | ---------- | ------------------------------------------------------------------------------------ |
| `LOG_CHANNEL`              | `stack`  |            | Log-Kanal.                                                                           |
| `LOG_STACK`                | `single` |            | `single` schreibt in `storage/logs/laravel.log`, `daily` legt eine Datei pro Tag an. |
| `LOG_DAILY_DAYS`           | `14`     |            | Aufbewahrung der Tagesdateien bei `daily`.                                           |
| `LOG_LEVEL`                | `debug`  | `warning`  | Mindeststufe für Logeinträge. `debug` protokolliert sehr ausführlich.                |
| `LOG_DEPRECATIONS_CHANNEL` | `null`   |            | Hinweise auf veraltete Funktionen (nur Entwicklung).                                 |

## E-Mail

Den Versand richtest du am einfachsten unter **Konfiguration → E-Mail-Versand** ein. Die dortigen Einstellungen haben Vorrang. Die folgenden Werte gelten, solange dort noch nichts gespeichert oder „Serverumgebung (.env)“ gewählt ist.

| Variable                             | Vorlage             | Produktion                                                   | Bedeutung                                                                                                          |
| ------------------------------------ | ------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ |
| `MAIL_MAILER`                        | `log`               | `smtp` (Pflicht, falls nicht in der Oberfläche eingerichtet) | `log` stellt nichts zu, sondern schreibt E-Mails ins Log. Weitere Werte: `smtp`, `sendmail`, `postmark`, `resend`. |
| `MAIL_SCHEME`                        | `null`              |                                                              | `smtps` für implizites TLS (Port 465). Bei `null` wird STARTTLS verwendet, wenn der Server es anbietet.            |
| `MAIL_HOST`                          | `127.0.0.1`         |                                                              | SMTP-Server.                                                                                                       |
| `MAIL_PORT`                          | `2525`              |                                                              | SMTP-Port, üblich sind 587 oder 465.                                                                               |
| `MAIL_USERNAME`                      | `null`              |                                                              | SMTP-Benutzer.                                                                                                     |
| `MAIL_PASSWORD`                      | `null`              |                                                              | SMTP-Passwort.                                                                                                     |
| `MAIL_FROM_ADDRESS`                  | `hello@example.com` | eigene Adresse                                               | Absenderadresse. Der Browser-Installer setzt `noreply@` plus die Domain.                                           |
| `MAIL_FROM_NAME`                     | `GymSLunity`        | Vereinsname                                                  | Absendername.                                                                                                      |
| `MAIL_EHLO_DOMAIN`                   | Host aus `APP_URL`  |                                                              | Name, mit dem sich der Server beim SMTP-Server meldet.                                                             |
| `POSTMARK_API_KEY`, `RESEND_API_KEY` | –                   |                                                              | Zugangsdaten, wenn `MAIL_MAILER` `postmark` oder `resend` ist.                                                     |

## Redis (optional)

Nur nötig, wenn `CACHE_STORE` oder `QUEUE_CONNECTION` auf `redis` stehen. `SESSION_DRIVER` bleibt immer `database`.

| Variable                  | Vorlage           | Bedeutung                                                        |
| ------------------------- | ----------------- | ---------------------------------------------------------------- |
| `REDIS_CLIENT`            | `phpredis`        | PHP-Erweiterung `redis`.                                         |
| `REDIS_HOST`              | `127.0.0.1`       | Redis-Server.                                                    |
| `REDIS_PORT`              | `6379`            | Port.                                                            |
| `REDIS_USERNAME`          | `null`            | Benutzer (Redis-ACL).                                            |
| `REDIS_PASSWORD`          | `null`            | Passwort.                                                        |
| `REDIS_URL`               | –                 | Alternativ die gesamte Verbindung als URL.                       |
| `REDIS_DB`                | `0`               | Datenbanknummer für Queue und allgemeine Nutzung.                |
| `REDIS_CACHE_DB`          | `1`               | Datenbanknummer für den Cache.                                   |
| `REDIS_PREFIX`            | aus dem App-Namen | Präfix, wenn sich mehrere Anwendungen einen Redis-Server teilen. |
| `REDIS_QUEUE`             | `default`         | Name der Warteschlange.                                          |
| `REDIS_QUEUE_RETRY_AFTER` | `90`              | Sekunden, nach denen ein hängender Auftrag erneut versucht wird. |

## Öffentliche Demo

Nur für eine öffentliche Demo-Instanz ohne echte Vereinsdaten. Im Demo-Modus zeigt die Anmeldeseite die Zugangsdaten der gemeinsamen Demo-Konten, jede Seite zeigt einen Hinweis-Banner, und die Demo-Konten brauchen keinen zweiten Faktor. `php artisan demo:reset` löscht die Datenbank und die hochgeladenen Dateien und legt den Musterverein neu an. Der Scheduler führt das jede Nacht aus; die täglichen Backups entfallen im Demo-Modus.

| Variable        | Vorlage              | Bedeutung                                                                               |
| --------------- | -------------------- | --------------------------------------------------------------------------------------- |
| `DEMO_MODE`     | `false`              | `true` aktiviert den Demo-Modus. **Niemals auf einer Instanz mit echten Daten setzen.** |
| `DEMO_PASSWORD` | `Demo-Passwort-2026` | Gemeinsames Passwort aller Demo-Konten. Es steht öffentlich auf der Anmeldeseite.       |
| `DEMO_RESET_AT` | `00:00`              | Uhrzeit der nächtlichen Zurücksetzung in der Zeitzone aus `APP_DISPLAY_TIMEZONE`.       |

## Entwicklung

| Variable                 | Bedeutung                                         |
| ------------------------ | ------------------------------------------------- |
| `PHP_CLI_SERVER_WORKERS` | Anzahl paralleler Worker für `php artisan serve`. |
| `MEMCACHED_HOST`         | Wird von GymSLunity nicht genutzt.                |

Für die automatisierten Tests gibt es eine eigene Vorlage [`.env.testing.example`](../.env.testing.example), siehe [entwicklung.md](entwicklung.md).
