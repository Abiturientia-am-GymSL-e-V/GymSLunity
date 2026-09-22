# GymSLunity – neue Entwicklungsinstanz

Laravel 13, Vue 3, Inertia 3, TypeScript und Vite 8. Die Oberfläche basiert auf dem offiziellen [Laravel Vue Starter Kit](https://github.com/laravel/vue-starter-kit), Tailwind CSS 4 und [shadcn-vue](https://www.shadcn-vue.com/). Das Starter Kit verwendet Vite+ für seine Build-, Formatierungs- und Lint-Befehle. Livewire wird nicht verwendet.

## Aktueller Stand

- Dev-URL: https://devmv.abiturientia-gymsl.de
- Startseite, Anmeldung/Abmeldung, Profil, Passwort, optionale Zwei-Faktor-Anmeldung und Hell-/Dunkelmodus sind eingerichtet.
- Öffentliche Registrierung ist deaktiviert. Ein Zugang wurde aus dem bisherigen aktiven Dev-Administratorkonto übernommen. Anmeldung mit dessen **E-Mail-Adresse und bisherigem Passwort**; alte Sitzungen werden nicht übernommen.
- Das Mitgliederverzeichnis unter `/mitglieder` bietet Suche, Filter, Sortierung, Spaltenauswahl und Seitennavigation. Ein Klick öffnet die vollständige Mitgliedsansicht mit Bearbeitung und Änderungshistorie. Die bisherigen Rollen des Dev-Administrators wurden für diesen Zugang übernommen.
- Die neue MariaDB-Datenbank heißt `gymslunity_dev_laravel`. Automatische Tests verwenden ausschließlich `gymslunity_dev_laravel_testing`.
- Die bisherige Datenbank bleibt erhalten. Die neue Anwendung liest daraus im laufenden Betrieb keine Daten.
- E-Mails werden nur lokal protokolliert (`MAIL_MAILER=log`). Passwort-Reset und Verifizierungsnachrichten erreichen daher kein externes Postfach.
- `APP_DEBUG=false` verhindert die Ausgabe von Fehlerdetails auf der öffentlich erreichbaren Dev-Domain. Fehler stehen in `storage/logs/laravel.log`.

## Archiv und Webserver

Der gesamte vorherige Ordnerinhalt einschließlich Konfiguration, Abhängigkeiten, Tests und Installationsarchiv liegt in **`.alt/`**. Er wurde verschoben, nicht gelöscht. Dateiinhalte, Eigentümer und Berechtigungen wurden beim Verschieben kontrolliert. Das Archiv selbst ist nur für root zugänglich; es dient als Quelltextreferenz, nicht als parallel betriebene Website.

`.alt/` ist von Git, Formatierung, PHP-Lint und Vite-Beobachtung ausgeschlossen; Tailwind durchsucht gezielt die neuen Ressourcen. Es gibt keine Abhängigkeit der neuen Anwendung von `.alt`.

Nginx liefert ausschließlich `/var/www/gymslunity/public` aus und führt nur `public/index.php` über PHP 8.4-FPM aus. `.env`, `.alt`, `vendor` und Anwendungsquelltext gehören **niemals** ins öffentlich ausgelieferte Verzeichnis. Konfiguration dieser Domain: `/etc/nginx/conf.d/devmv.conf`.

Die vorherige Nginx-Konfiguration und das Archiv-Inventar liegen geschützt unter `/var/backups/gymslunity-laravel-bootstrap/`. Eine Rückkehr zum Altstand muss sowohl den Projektordner als auch die Domain-Konfiguration berücksichtigen. `.alt` ist eine lokale Arbeitskopie und ersetzt kein externes Backup.

## Entwicklung auf diesem Server

Voraussetzungen: PHP **8.4** für die konkret gesperrten Abhängigkeiten, Composer 2, Node **24 LTS**, npm und MariaDB. PHP benötigt unter anderem PDO MySQL, mbstring, XML, cURL und ZIP. Die genauen Paketversionen stehen in `composer.lock` und `package-lock.json`.

```bash
cd /var/www/gymslunity
umask 0022
nvm use
php .tools/composer.phar install
npm ci
npm run build
```

Node 24 ist über das vorhandene nvm installiert; `.nvmrc` wählt diese Version nur für dieses Projekt. Unter `.tools/composer.phar` liegt lokal Composer 2.10.3, weil der systemweite Composer älter ist. Auf anderen Rechnern genügt eine aktuelle Composer-2-Installation (`composer` statt `php .tools/composer.phar`). `.tools` gehört nicht ins Repository. Composer muss Abhängigkeiten mit Leserechten für PHP-FPM installieren; mit `umask 0007` und einem anderen Eigentümer kann die Website sonst ausfallen.

Die HTTPS-Domain verwendet die gebauten Dateien aus `public/build`. Nach Frontend-Änderungen genügt `npm run build`; ein dauerhafter Node-Prozess ist dafür nicht erforderlich.

Für einen lokalen Checkout mit Hot Reload:

```bash
composer run dev
```

Dabei `APP_URL=http://localhost:8000` und `SESSION_SECURE_COOKIE=false` in der **lokalen** `.env` verwenden. Der lokale Vite-Server wird nicht automatisch über die HTTPS-Dev-Domain veröffentlicht. Die Server-Konfiguration mit sicheren Cookies für HTTPS beibehalten.

## Neue Installation aus dem Quelltext

`.alt`, `.env`, `.env.testing`, `.tools`, `node_modules` und `vendor` nicht in ein Quelltextpaket übernehmen. Der alte Browser-Installer befindet sich ausschließlich im Archiv und installiert die alte Anwendung.

```bash
nvm use
composer install
cp .env.example .env
# .env bearbeiten: APP_URL und eine neue, leere Datenbank konfigurieren.
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan app:create-user --role=admin
```

Die Beispielkonfiguration verwendet SQLite; dafür `pdo_sqlite` installieren und eine leere `database/database.sqlite` anlegen. Alternativ `DB_CONNECTION=mysql` und eigene MariaDB-Zugangsdaten setzen. Für eine HTTPS-Instanz zusätzlich `SESSION_SECURE_COOKIE=true` setzen. Einen bereits verwendeten `APP_KEY` nicht erneut erzeugen.

Dem PHP-Prozess Schreibrechte nur für `storage/` und `bootstrap/cache/` geben; `.env` muss für ihn lesbar sein. Nginx/Apache auf `public/` ausrichten. Das Kommando `app:create-user` fragt das Passwort verdeckt ab. Es gibt keinen mitgelieferten Benutzer mit Standardpasswort und keine automatische Übernahme weiterer Konten.

## Tests

```bash
nvm use
npm run check
npm run types:check
php .tools/composer.phar test
npm run build
```

Auf diesem Server liegt eine private `.env.testing` für eine separate MariaDB-Testdatenbank vor. `RefreshDatabase` darf deren Tabellen löschen und neu anlegen. `tests/TestCase.php` bricht **vor** solchen Operationen ab, wenn die konfigurierte Datenbank nicht `:memory:` heißt oder auf `_testing` endet. Die reguläre Datenbank niemals als Testdatenbank eintragen.

Auf anderen Rechnern mit SQLite:

```bash
cp .env.testing.example .env.testing
php artisan key:generate --env=testing
php artisan test
```

Die Tests des Starter Kits prüfen unter anderem Anmeldung, Passwort-Reset, E-Mail-Verifizierung, Profil und Zwei-Faktor-Anmeldung. Projekttests prüfen zusätzlich die gesperrte Registrierung und die sichere Anlage eines Kontos über die Konsole.

## Wo wird weiterentwickelt?

| Aufgabe                             | Ort                                                               |
| ----------------------------------- | ----------------------------------------------------------------- |
| Laravel-Routen                      | `routes/web.php`, `routes/settings.php`                           |
| Request-Verarbeitung                | `app/Http/Controllers/`, `app/Http/Requests/`                     |
| Modelle und Migrationen             | `app/Models/`, `database/migrations/`                             |
| Vue-Seiten                          | `resources/js/pages/`                                             |
| Seitenrahmen und Navigation         | `resources/js/layouts/`, `resources/js/components/AppSidebar.vue` |
| Wiederverwendbare UI-Komponenten    | `resources/js/components/ui/`                                     |
| Farben, Schrift, Abstände, Bewegung | `resources/css/app.css`                                           |
| Automatische Tests                  | `tests/Feature/`, `tests/Unit/`                                   |

shadcn-vue liefert editierbaren Komponentenquelltext, unter anderem Buttons, Dialoge, Menüs und Eingaben. Tailwind steuert die Gestaltung; dezente Dialog- und Menüanimationen sind bereits enthalten. Die Betriebssystemeinstellung für reduzierte Bewegung wird berücksichtigt. Layout und Farben können später angepasst werden, ohne die Fachlogik auszutauschen.

## Mitgliederverzeichnis

Die Hauptansicht ist unter `/mitglieder` und über den Menüpunkt **Mitglieder** erreichbar. Sie liest die neue Tabelle `members` in der Laravel-Datenbank. Suche, Filter und Sortierung werden serverseitig angewendet; pro Seite werden höchstens 100 Datensätze ausgeliefert.

- Suche nach Mitgliedsnummer, Vor-/Nachname, weiterem Vornamen, E-Mail, Mobilnummer, Straße, PLZ oder Ort. Mehrere Suchwörter müssen gemeinsam passen; `%` und `_` werden als Text behandelt.
- Kombinierbare Filter: Mitgliedschaft, Funktion in der Abteilung und Funktion im Hauptverein sowie die in der Konfiguration als Filter aktivierten Zusatzfelder. Funktionen können gezielt ausgewählt oder nach „Mit Funktion“ / „Ohne Funktion“ gefiltert werden. Leere Zeichenketten und NULL gelten beide als ohne Funktion.
- Filter, Sortierung und Seite stehen in der URL. Die Spaltenauswahl wird lokal im Browser gespeichert; Mitgliedsdaten werden dafür nicht im Local Storage abgelegt.
- Standardspalten: Mitgliedsnummer, Name, Kontakt, Wohnort, Mitgliedschaft und Funktionen sowie entsprechend konfigurierte Zusatzfelder. Weitere Spalten lassen sich über **Spalten** einblenden.
- Anmeldung und verifizierte E-Mail sind erforderlich. `MemberPolicy` erlaubt den Lesezugriff nur den Rollen `admin`, `vereinsverwaltung`, `mv` und `auditor`. Andere Konten erhalten HTTP 403 und keinen Menüeintrag. Rollen sind nicht über das Profil bearbeitbar.
- Zusätzliche Zugänge lassen sich mit `php artisan app:create-user --role=mv` anlegen. Mehrere `--role`-Optionen sind möglich. Ohne Rollenoption erhält das neue Konto keinen Zugriff auf das Verzeichnis.

In dieser Entwicklungsinstanz sind **20 fiktive Mustermitglieder** angelegt. Sie verwenden `example.invalid` und unterschiedliche Jahrgänge, Mitgliedschaften und Funktionen. Sie sind neue Beispieldatensätze, keine vollständige Migration der bisherigen Datenbank.

```bash
php artisan db:seed --class=DemoMembersSeeder
```

Dieser optionale Seeder läuft nur in `local` / `testing` und nur bei leerer Mitgliedertabelle. Bei vorhandenen Mitgliedern verändert er nichts. `DatabaseSeeder` legt weiterhin keine Mustermitglieder automatisch an.

Die Listenabfrage bleibt auf ihre explizite Spaltenliste begrenzt und enthält keine Bankdaten, PDF-BLOBs oder Dokumentenlinks. Postleitzahlen bleiben Zeichenketten mit führenden Nullen.

Die wesentlichen Dateien sind `app/Models/Member.php`, `app/Policies/MemberPolicy.php`, `app/Http/Requests/Members/IndexMembersRequest.php`, `app/Http/Controllers/Members/MemberIndexController.php` sowie die Vue-Seite `resources/js/pages/members/Index.vue` und ihre Komponenten unter `resources/js/components/members/`.

Die Feature-Tests in `tests/Feature/Members/MemberIndexTest.php` prüfen Berechtigungen, Suchbegriffe, kombinierte Filter, leere Werte, stabile Sortierung, Seitengrenzen, ungültige Parameter und die wiederholte Ausführung des Demo-Seeders.

## Mitgliedsansicht und Bearbeitung

`/mitglieder/{mitgliedsnummer}` zeigt persönliche Angaben, Kontakt/Adresse, Mitgliedschaft, konfigurierte Zusatzfelder, Funktionen, Förderbeitrag, SEPA-Angaben und abweichenden Kontoinhaber. Die Mitgliedsnummer bleibt unveränderlich. Das Feld „Volljährig“ wird aus dem Geburtsdatum berechnet. Leere Angaben werden ausdrücklich als nicht hinterlegt angezeigt; bei Funktionen lautet die Anzeige „Keine“.

- **Bearbeiten** gibt die Felder frei. **Speichern** übernimmt die Änderung und zeigt den neuen Stand; **Speichern und Schließen** kehrt direkt zur Liste zurück. **Schließen** fragt bei ungespeicherten Änderungen nach, ob diese verworfen werden sollen.
- Filter, Sortierung und Seite bleiben in der Rücksprung-URL erhalten. Die Listen- und Fensterposition wird beim Öffnen im Session Storage des Tabs für bis zu eine Stunde gemerkt; die Spaltenauswahl bleibt im Local Storage. Die Liste wird beim Schließen neu abgefragt, damit geänderte Einträge und Filterergebnisse aktuell sind. Ohne Browser-Speicher funktionieren Navigation und Filter weiterhin, die genaue Scrollposition kann dann entfallen.
- Details, Historie und Dokumentdownloads sind für `admin`, `vereinsverwaltung`, `mv` und `auditor` sichtbar. Nur die ersten drei Rollen dürfen bearbeiten. Die Prüfung erfolgt serverseitig, unabhängig von sichtbaren Buttons.
- `UpdateMemberRequest` validiert ausschließlich erlaubte Felder: Pflichtnamen, E-Mail, Auswahllisten, Datum, typisierte Zusatzfelder, Förderbeitrag sowie IBAN-Format und Prüfsumme. Datumsbeziehungen werden im Schreibvorgang gegen den vollständigen Datensatz geprüft. Vorhandene Auswahlwerte bleiben bei späteren Erweiterungen lesbar und unverändert speicherbar.
- `UpdateMember` sperrt die Zeile innerhalb einer Transaktion und vergleicht `lock_version`. Eine inzwischen geänderte Version wird nicht überschrieben. Die Oberfläche behält die eigenen Eingaben und bietet das ausdrückliche Laden des aktuellen Standes an.

## Änderungshistorie und Dokumente

Die Migration `2026_09_22_010000_add_member_details_and_history.php` ergänzt die Detailfelder sowie `member_changes` und `member_documents`. Auf einer anderen bestehenden Laravel-Instanz vor Verwendung der neuen Oberfläche `php artisan migrate` und `npm run build` ausführen.

Jede tatsächliche Änderung über `UpdateMember` speichert vollständige Vorher-/Nachher-Werte der bearbeitbaren Angaben, die geänderten Feldnamen, eine fortlaufende Datensatzversion, Zeitpunkt, Benutzer-ID und damaligen Benutzernamen. Datenänderung und Historieneintrag werden gemeinsam bestätigt oder zurückgerollt. Unverändertes Speichern erzeugt keinen Eintrag. Die Historie steht rechts neben den Angaben, auf kleinen Bildschirmen darunter; sie lädt zehn Einträge pro Seite.

Die Snapshots erlauben eine spätere Wiederherstellung über einen eigenen, ebenfalls protokollierten Schreibvorgang; ein Wiederherstellungsbutton ist noch nicht implementiert. Die neue Historie beginnt mit den Änderungen in der neuen Anwendung. Alte Historieneinträge und echte Mitgliederdaten wurden noch nicht aus der bisherigen Datenbank importiert.

`MemberChange` unterbindet Änderungen und Löschungen über einzelne Eloquent-Modelle. Das ist eine Anwendungshistorie, **noch kein gegen direkte SQL-Zugriffe abgesichertes Revisionsarchiv**: Query-Builder-/SQL-Änderungen umgehen Modellereignisse und `UpdateMember`. Künftige Schreibwege müssen denselben Dienst verwenden. Für weitergehende Absicherung sind insbesondere getrennte Datenbankrechte, kontrollierte Schreibwege und unabhängige Backups erforderlich.

Antrag und SEPA-Mandat liegen getrennt in `member_documents` als Originalbytes (`LONGBLOB` unter MariaDB/MySQL), mit Dokumentart und Online-Einreichungskennzeichen. Die Detailabfrage lädt ausschließlich Metadaten. Ein gesonderter, berechtigungsgeprüfter Download liefert hinterlegte PDFs aus. Der normale Mitglieder-PATCH akzeptiert weder Dokumentbytes noch Dokumentkennzeichen und kann diese nicht verändern. Upload, Austausch und Übernahme alter Dokumente folgen als eigener Ablauf; Mustermitglieder besitzen zunächst keine PDFs.

`tests/Feature/Members/MemberDetailTest.php` prüft vollständige Details, Rechte, Eingabevalidierung, Datumskonsistenz, unveränderliche Felder, Transaktionsrollback bei Auditfehlern, Versionskonflikte, unverändertes Speichern, Historienseiten, sichere Rücksprung-URLs und bytegenau unveränderte PDF-BLOBs beim Ändern normaler Mitgliedsdaten.

## Konfiguration

Administratoren öffnen **Konfiguration** in der Seitenleiste:

- `/konfiguration/verein`: Vereinsname und Kurzname, Gründungsdatum, Kontakt/Adresse, Vereinsregister und Steuern, Gemeinnützigkeit sowie Bank- und SEPA-Stammdaten. Der Kurzname erscheint in der Navigation. Der Vereins-Ländercode dient als Vorgabe für Ortssuchen bei leeren Länderangaben.
- `/konfiguration/mitgliedsfelder`: Beschriftung, Gruppe, Reihenfolge, Pflichtfeld und Aktivierung. Eigene Zusatzfelder unterstützen Text, ganze Zahl, Dezimalzahl (zwei Nachkommastellen), Datum, Ja/Nein und Auswahl. Sie können als Filter und Tabellenspalte angeboten werden. Maximal 250 Felder sind möglich.
- `/konfiguration/benutzer`: Konten anlegen/bearbeiten, Rollen zuweisen, E-Mail administrativ bestätigen, Passwort setzen und Konto deaktivieren. Neue Passwörter benötigen mindestens 12 Zeichen. Passwörter werden nicht per E-Mail versendet. Konten werden über die Verwaltung deaktiviert; die eigene Kontolöschung steht im Profil zur Verfügung.

Rollen sind zunächst fest vorgegeben: `admin` verwaltet Mitglieder und Konfiguration; `vereinsverwaltung`/`mv` bearbeiten Mitglieder; `auditor` hat Lesezugriff einschließlich Export, Historie und Dokumenten. Die bisherigen Rollen `bh`, `bm` und `kp` bleiben verfügbar, haben derzeit aber keinen eigenen freigeschalteten Bereich. Beliebige neue Rollen und eine individuelle Berechtigungsmatrix sind nicht implementiert.

Ein deaktiviertes Konto kann sich nicht anmelden oder eine bestehende Sitzung weiterverwenden. Sperrung und administrativer Passwortwechsel beenden andere Sitzungen. Das eigene Administratorkonto lässt sich hier nicht sperren, herabstufen oder unverifizieren. Der letzte aktive, verifizierte Administrator kann sich auch über sein Profil nicht löschen oder durch einen E-Mail-Wechsel den Konfigurationszugriff verlieren.

Änderungen an Vereinsdaten, Feldern, Reihenfolge und Konten werden in `configuration_changes` mit Akteur und Vorher-/Nachher-Daten protokolliert. Passwörter, Passwort-Hashes und 2FA-Geheimnisse werden dort nicht gespeichert. Eine Anzeige dieser Konfigurationshistorie ist noch nicht enthalten. Versionsprüfungen erkennen veraltete Formulare; sie gelten sowohl für Mitgliedsdaten als auch für die Feldkonfiguration und Benutzerkonten.

### Felder und Auswahlwerte

Die Anwendung verwendet `member_field_definitions`. Freie Werte liegen als JSON in `members.custom_values`; Kernfelder behalten ihre vorhandenen Spalten und Datentypen. Mitgliedschaften starten mit **Aktiv/ordentliches Mitglied** und **Fördermitglied**, ergänzt um bereits vorhandene Mitgliedschaftswerte. Auswahloptionen für Vereinsfunktionen und Mitgliedschaften sind erweiterbar. Die Zahlungsart bietet **SEPA-Lastschrift**, **Überweisung**, **Bar** und **Sonstiges**, jeweils aktivierbar/deaktivierbar.

Vorname, Nachname und Mitgliedschaft bleiben Pflichtfelder. Datentypänderungen bei bereits belegten Zusatzfeldern werden abgewiesen. Deaktivierte Felder mit vorhandenen Werten erscheinen in der Mitgliedsansicht unter **Archivierte Angaben**. Ihre Daten bleiben beim Bearbeiten anderer Felder erhalten. Deaktivierte Auswahloptionen sind für neue Zuweisungen gesperrt; ein bereits gespeicherter Wert bleibt lesbar und unverändert speicherbar. Umbenennen einer Option verändert deren Beschriftung, nicht den gespeicherten Schlüssel.

Neue Mitgliedsänderungen speichern die damaligen Feldbeschriftungen und Auswahlbezeichnungen zusätzlich in `member_changes.field_schema`. Eine spätere Änderung der Konfiguration verändert dadurch nicht die Anzeige dieser historischen Einträge. `MemberValidation` prüft typisierte Werte sowohl vor dem Controller als auch innerhalb des gesperrten Schreibvorgangs.

Die Migration `2026_09_22_020000_add_club_configuration.php` wurde auf dieser Dev-Instanz ausgeführt. Sie überführt Abschluss, Jahrgang und ehemaliger Schüler in normale, konfigurierbare Zusatzfelder und entfernt die drei bisherigen Schulspalten. Vorhandene Mitgliederdaten und historische Schlüssel werden entsprechend übernommen. Vor der Migration wurde eine private Sicherung unter `.tools/pre-configuration-*.sql` erstellt. Auf einer weiteren Instanz zuerst ein Backup erstellen, dann `php artisan migrate` und `npm run build` ausführen. Ein automatisches Rollback dieser Datenmigration ist absichtlich nicht implementiert; dafür das passende Backup zusammen mit dem damaligen Code wiederherstellen.

### Land und Postleitzahl

Die Ländereingabe bietet eine sichtbare, durchsuchbare Vorschlagsliste mit Pfeiltasten, Enter und Escape. Deutsche Ländernamen und ISO-Codes werden bei Auswahl normalisiert; freie Eingaben bleiben möglich. Die Ortssuche verwendet eine lokale GeoNames-Zuordnung für **Deutschland**. Bei einem eindeutigen Treffer wird ein leerer Ort bzw. nach einer PLZ-Änderung der bisherige Ort ergänzt; bei mehreren Treffern erscheint eine Auswahl. Eine während der Anfrage manuell geänderte Ortsangabe wird nicht überschrieben. Für andere Länder oder unbekannte Postleitzahlen erfolgt die Eingabe manuell. Es werden keine Mitgliedsadressen an einen externen Dienst geschickt.

Quelle, Lizenz und Aktualisierung der Zuordnung stehen in `resources/data/postal/README.md`. Register- und Steuernummern verwenden Textfelder mit abgeschaltetem Autofill und eindeutigen Feldnamen, damit Browser diese möglichst nicht als Kreditkarteneingaben behandeln. Individuelle Autofill-/Passwortmanager-Einstellungen können diese Angaben übersteuern.

### Auswahl und Export

Checkboxen markieren einzelne Mitglieder oder die aktuelle Seite; die Auswahl bleibt bei der Seitennavigation erhalten. Geänderte Suchbegriffe/Filter leeren die Auswahl. **Exportieren** lädt die Auswahl oder die gesamte gefilterte Liste als CSV bzw. JSON herunter, einschließlich Datensätzen auf anderen Seiten. Reihenfolge und sichtbare Spalten werden berücksichtigt. Gruppierte Spalten werden in einzelne Datenfelder aufgeteilt.

CSV verwendet UTF-8 mit BOM und Semikolon; Bezeichnungen, Ja/Nein-Werte und Auswahlbeschriftungen sind lesbar. Werte, die Tabellenkalkulationen als Formeln interpretieren könnten, werden mit einem Apostroph geschützt. Bei CSV-Importen Postleitzahl-Spalten als Text importieren, um führende Nullen zu erhalten. JSON behält Feldschlüssel und Werttypen. Die Export-Endpunkte prüfen dieselben Leserechte wie die Liste und erlauben ausschließlich aktive Verzeichnisfelder; Bankdaten und PDF-Inhalte gehören nicht zum Listenexport.

### Prüfung

Zusätzliche Feature-Tests unter `tests/Feature/Configuration/` und `tests/Feature/Members/` prüfen dynamische Felder, deaktivierte Auswahlwerte, archivierte Angaben, numerische Filter/Sortierung, Rollen, Kontosperren einschließlich ausstehender 2FA-Anmeldungen, letzte Administratoren, Versionskonflikte, Ortszuordnung sowie CSV-/JSON-Export einschließlich Formel-Schutz. Die HTTP-Preload-Header sind auf sechs Assets begrenzt, damit komplexe Seiten in Nginx nicht an dessen Standard-Headerpuffer scheitern; vollständige Preload-Tags bleiben im HTML.

Profil, Sicherheit, 2FA-Einrichtung, Wiederherstellungscodes, Erscheinungsbild und Kontolöschung sind auf Deutsch beschriftet. Deutsche Validierungs- und Authentifizierungsnachrichten liegen unter `lang/de/` und `lang/de.json`.

## Herkunft

Grundlage: `laravel/vue-starter-kit`, Commit `d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab` vom 21.09.2026, MIT-lizenziert. Die Paket-Lockdateien fixieren den installierten Stand. Das ursprüngliche Git-Repository wurde nicht als Projektgeschichte übernommen.
