# An GymSLunity mitentwickeln

Diese Seite beschreibt, wie der Code aufgebaut ist und welche Regeln im Projekt gelten. Lies sie, bevor du größere Änderungen machst. Für alles, was die Oberfläche betrifft, gilt zusätzlich die [STYLE.md](../STYLE.md).

## Befehle

```bash
composer setup        # Erstinstallation für die Entwicklung
composer run dev      # Entwicklungsserver
composer ci:check     # alles, was die CI prüft (vor jedem Push ausführen)
composer test:mariadb # nur die PHPUnit-Tests gegen MariaDB
composer lint         # PHP automatisch formatieren
npm run check:fix     # Frontend automatisch formatieren
php artisan test --filter=NameDesTests
```

`composer ci:check` führt Frontend-Lint und Formatierung, `vue-tsc`, Pint, PHPStan (Level 7) und alle PHPUnit-Tests aus, einmal mit SQLite `:memory:` (Vorlage in `.env.testing.example`) und einmal mit MariaDB. SQLite ist nachsichtiger als MariaDB/MySQL, etwa bei der Länge von Bezeichnern, Spaltengrößen und Tabellenänderungen in Transaktionen; Fehler dort fallen nur im zweiten Lauf auf.

Für den MariaDB-Lauf braucht es lokal einen Server auf `127.0.0.1:3306` mit einer eigenen Testdatenbank, zum Beispiel unter macOS:

```bash
brew install mariadb && brew services start mariadb
mariadb -e "CREATE DATABASE gymslunity_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'gymslunity_testing'@'127.0.0.1' IDENTIFIED BY 'testing';
  GRANT ALL ON gymslunity_testing.* TO 'gymslunity_testing'@'127.0.0.1';"
```

Die Tests leeren diese Datenbank bei jedem Lauf; verwende nie eine Datenbank mit echten Daten. Tests, die das Schema ändern (Migrationen ausführen, Tabellen löschen), nutzen `Tests\Concerns\ChangesDatabaseSchema` statt `RefreshDatabase`: MariaDB/MySQL bestätigen Schemaänderungen sofort, eine Test-Transaktion kann sie nicht zurückrollen.

## Aufbau des Backends

Der Code ist nach Fachbereichen geordnet, nicht nach technischen Schichten:

| Ort                               | Inhalt                                                                                         |
| --------------------------------- | ---------------------------------------------------------------------------------------------- |
| `app/<Bereich>/`                  | Fachlogik, z. B. `app/Finance/IssueFinanceInvoice.php` oder `app/Payments/SepaDirectDebit.php` |
| `app/Http/Controllers/<Bereich>/` | schlanke Controller, die Fachlogik aufrufen und Inertia-Seiten rendern                         |
| `app/Http/Requests/<Bereich>/`    | Validierung (siehe unten)                                                                      |
| `app/Models/`                     | Eloquent-Modelle                                                                               |
| `resources/views/`                | PDF- und E-Mail-Vorlagen                                                                       |

Bereiche sind unter anderem `Members`, `Payments` (Mitgliedsbeiträge), `Finance` (allgemeines Rechnungswesen), `Donations`, `Forms`, `Calendar`, `Bookings`, `Inventory`, `Communication`, `SelfService` und `Configuration`.

### Regeln im Backend

- **`declare(strict_types=1)`** steht in jeder PHP-Datei. Pint prüft das in der CI.
- **Vereinseinstellungen** liest du über den Service `App\Configuration\ClubSettings`, den du per Konstruktor oder Methode injizierst. Er lädt die Einstellungen einmal pro Request und bündelt abgeleitete Regeln, zum Beispiel `displayName()`, `logoUrl()`, `enabled('…')`, `sepaReady()` und `mandateReady()`. Schreibende Aktionen sperren die Zeile weiterhin direkt mit `ClubSetting::query()->whereKey(1)->lockForUpdate()`. Danach lädt der Service automatisch neu.
- **Ob SEPA eingerichtet ist**, entscheidet nur `ClubSettings::sepaReady()`. Das sind genau die Felder, die der SEPA-Export braucht: Vereinsname, IBAN und Gläubiger-ID. Baue keine eigene Prüfung.
- **Geldbeträge** werden als ganze Cent gespeichert (`int`). Eingaben wandelt `App\Payments\Money::cents()` um.
- **Ausgestellte Dokumente** (Rechnungen, Quittungen, Zuwendungsbestätigungen) werden nie verändert. Korrekturen laufen über verknüpfte Storno- oder Widerrufsdokumente.
- **Platzhalter** in einstellbaren Texten haben die Form `{{verein.<schlüssel>}}` und müssen überall gleich heißen.
- **Berechtigungen** laufen über Rollen, Gates (`AppServiceProvider`) und optionale Module (`module:<name>`). Ein neuer Bereich braucht Modul, Gate, Routen-Middleware und den `can`-Eintrag in `HandleInertiaRequests`.

### Validierung

- Eine **Form-Request-Klasse** unter `app/Http/Requests/<Bereich>/` bekommt jeder Regelsatz, der
    - mehr als drei Eingabefelder hat (verschachtelte Regeln wie `items.*` zählen zu ihrem Feld),
    - an mehreren Stellen verwendet wird, zum Beispiel dieselben Filter in Übersicht und PDF-Bericht, oder
    - Eingaben vorher normalisiert, zum Beispiel IBAN-Leerzeichen oder Dezimalkomma.
- **Direkt im Controller** mit `$request->validate([...])` bleiben nur kleine Regelsätze mit höchstens drei Feldern.
- Prüfungen, die mehrere Felder zusammen betreffen, gehören in `after()` des Requests, Normalisierung in `prepareForValidation()`, Zugangsprüfungen in `authorize()`.
- Filter für Dokumentenbücher erben von `App\Http\Requests\DocumentFilterRequest`.

## Aufbau des Frontends

| Ort                                  | Inhalt                                                     |
| ------------------------------------ | ---------------------------------------------------------- |
| `resources/js/pages/`                | Inertia-Seiten                                             |
| `resources/js/components/<bereich>/` | Komponenten eines Bereichs                                 |
| `resources/js/components/ui/`        | shadcn-vue-Basiskomponenten (nicht von Hand umformatieren) |
| `resources/js/lib/`                  | gemeinsame Hilfsfunktionen                                 |
| `resources/js/types/`                | gemeinsame TypeScript-Typen                                |

### Regeln im Frontend

- **Große Seiten aufteilen.** Hat ein Bereich Reiter mit eigenen URLs und kaum gemeinsamen Zustand, bekommt jeder Reiter eine eigene Seite mit gemeinsamem Rahmen (Beispiele: `pages/payments/` mit `components/payments/PaymentsPage.vue`, `pages/finance/`). Teilen sich Reiter viel Zustand, bleibt eine Seite und in sich geschlossene Abschnitte werden Komponenten (Beispiel: `pages/Kommunikation.vue`). Eine Seite sollte nicht über rund 700 Zeilen wachsen.
- **Komponenten** bekommen Daten über Props und melden Aktionen über Events. Zustand, den mehrere Abschnitte brauchen, etwa Dialoge oder Upload-Formulare mit Warnung bei ungespeicherten Änderungen, bleibt in der Seite.
- **Formatierung** nur über `lib/format.ts`: `formatMoney(cents)`, `formatDate(value)`, `formatDateTime(value)` und `formatNumber(value)`.
- **Datei-Downloads per `fetch`** laufen über `lib/download.ts` (`postDownload`, `saveBlob`, `xsrfToken`).
- **Typen**, die Seite und Komponenten teilen, gehören nach `types/<bereich>.ts`.
- **Imports** stehen in dieser Reihenfolge: erst externe Pakete, dann `@/…`, jeweils alphabetisch. Der Linter meldet unbenutzte Imports in Vue-Dateien nicht zuverlässig, prüfe das selbst.
- Für Layout, Formulare, Datumsfelder, Dropdowns und E-Mails gilt die [STYLE.md](../STYLE.md).

## Zusammenarbeit

- Vor jedem Push muss `composer ci:check` grün sein.
- Neue Fachlogik bekommt Feature-Tests in `tests/Feature/<Bereich>/`.
- Bei größeren Änderungen hilft es, wenn die zweite Person kurz über den Diff auf GitHub schaut, auch ohne formalen Pull Request.
