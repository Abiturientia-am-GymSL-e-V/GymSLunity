# GymSLunity UI- und Frontend-Styleguide

Diese Datei ist die verbindliche Gestaltungsgrundlage für neue und geänderte Frontend-Bestandteile. Ziel ist eine ruhige, einheitliche Oberfläche, die auf Mobilgeräten, iPads und Desktop-Browsern zuverlässig funktioniert.

Bestehende gemeinsame Komponenten und Design-Tokens haben Vorrang vor neuem, lokalem Markup oder hart codierten Farben. Wenn ein neues wiederkehrendes Muster nötig wird, soll zuerst eine gemeinsame Komponente oder globale Utility entstehen und anschließend diese Datei ergänzt werden.

## Grundsätze

- Mobile First: Die Standarddarstellung muss auf schmalen Bildschirmen funktionieren. Zusätzliche Spalten oder horizontale Aktionsgruppen werden erst an einem passenden Breakpoint aktiviert.
- Vorhandene Komponenten wiederverwenden. Insbesondere `Input`, `Label`, `Button`, `Textarea`, `StatusAlert`, `InputError` und `SearchableDropdown` nicht lokal nachbauen.
- Farben ausschließlich über semantische Tokens wie `bg-card`, `text-foreground`, `text-muted-foreground`, `border-input`, `text-destructive` oder über vorhandene Komponentenvarianten einsetzen.
- Heller und dunkler Modus müssen ohne separate Seitenlogik funktionieren. Keine fest verdrahteten weißen oder schwarzen Flächen für die Anwendungsoberfläche verwenden.
- Inhalte und Bedienbarkeit dürfen nicht von einer bestimmten Maus-, Touch- oder Browserimplementierung abhängen.
- Neue Oberflächen müssen visuell an benachbarte Unterseiten angeglichen werden. Als Referenzen dienen insbesondere die Beitragsseiten unter `resources/js/pages/payments/`, `resources/js/pages/configuration/Club.vue` und die Mitgliederseiten.

## Seitenaufbau

Normale Verwaltungsseiten verwenden grundsätzlich diesen äußeren Rahmen:

```vue
<div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
    <header>
        <h1 class="text-2xl font-semibold tracking-tight">Seitentitel</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Kurze Beschreibung der Seite.
        </p>
    </header>
    <!-- Navigation und Inhalt -->
</div>
```

- `max-w-[1200px]` ist der Standard für Verwaltungsseiten. Das gilt auch für Kalender- und Buchungsansichten, damit der Abstand zwischen Sidebar und Seiteninhalt auf breiten Bildschirmen überall gleich bleibt. Breite Kalender oder Tabellen scrollen bei Bedarf innerhalb ihrer Karte horizontal.
- Der Seitenabstand beträgt `p-4 sm:p-6`, der vertikale Abstand der Hauptbereiche `space-y-6`.
- Mehrteilige Seiten verwenden den vorhandenen Bereichs- oder Tab-Navigator direkt unter dem Seitenkopf.
- Breadcrumbs werden über die Layout-Metadaten bereitgestellt und nicht innerhalb der Seite dupliziert.
- Flex- und Grid-Kinder, die schrumpfen müssen, erhalten `min-w-0`. Der App-Inhalt darf keinen horizontalen Seiten-Overflow erzeugen.

## Typografie und Gewichtung

Die visuelle Hierarchie soll mit wenigen, wiederkehrenden Stufen auskommen:

| Element                          | Vorgabe                                                               |
| -------------------------------- | --------------------------------------------------------------------- |
| Seitentitel                      | `text-2xl font-semibold tracking-tight`                               |
| Seitenbeschreibung               | `mt-1 text-sm text-muted-foreground`                                  |
| Größere Bereichsüberschrift      | `text-lg font-semibold`                                               |
| Karten- oder Tabellenüberschrift | `font-semibold`, bei kompakten Kopfzeilen optional `text-sm`          |
| Feldbeschriftung                 | gemeinsame `Label`-Komponente; `text-sm font-medium`                  |
| Normaler UI-Text                 | Standardgröße oder `text-sm` in kompakten Oberflächen                 |
| Hilfs- und Metatext              | `text-sm text-muted-foreground`; nur sehr kompakte Hinweise `text-xs` |
| Kennzahlen                       | große Schrift nach Kontext, höchstens `font-semibold`                 |

- `font-bold` sparsam verwenden. Für Überschriften, Beschriftungen und Aktionen ist `font-semibold` beziehungsweise `font-medium` der Standard.
- Sekundäre Informationen werden über Farbe und Größe zurückgenommen, nicht durch sehr geringe Deckkraft oder winzige Schrift.
- Links im Fließtext müssen als Links erkennbar sein, üblicherweise durch `underline`, `hover:underline` oder die Link-Variante von `Button`.
- Text darf bei langen Namen oder Referenzen das Layout nicht sprengen. Je nach Inhalt `break-words`, `truncate` zusammen mit `min-w-0` oder einen Umbruch einsetzen.

## Flächen, Karten und Abschnitte

- Standardkarte: `rounded-xl border bg-card p-5`.
- Kompakte Filter- oder Werkzeugleiste: `rounded-xl border bg-card p-4`.
- Karten mit eigener Kopfzeile: äußerer Container `rounded-xl border bg-card`; Kopfzeile `border-b px-5 py-4`; Inhalt `p-5`.
- Tabellenkarten verwenden `overflow-hidden rounded-xl border bg-card`; die Tabelle bekommt bei Bedarf innerhalb der Karte einen eigenen `overflow-x-auto`-Wrapper.
- Zusammengehörige Inhalte bleiben in einer Fläche. Nicht für jedes einzelne Feld oder jeden Satz eine zusätzliche Karte erzeugen.
- Interaktive Karten benötigen einen sichtbaren Hover- und Fokuszustand. Nicht interaktive Karten erhalten keinen irreführenden Hovereffekt.

## Abstände und Layout-Rhythmus

- Zwischen Hauptbereichen: `space-y-6` oder `gap-6`.
- Innerhalb von Formularen und Karten: `space-y-5` beziehungsweise `gap-5`.
- Zwischen Beschriftung, Eingabefeld und Fehlermeldung: Feld-Wrapper immer `space-y-2`.
- Zwischen eng zusammengehörigen Textzeilen: `space-y-1` oder eine Beschreibung mit `mt-1`.
- Aktionsgruppen: `gap-2` oder `gap-3`; auf kleinen Bildschirmen `flex-wrap` oder `flex-col` verwenden.
- Keine negativen Margins oder pixelgenauen Verschiebungen einsetzen, um ein grundsätzlich falsches Grid zu kaschieren.

## Formulare

Ein normales Feld folgt immer dieser Struktur:

```vue
<div class="min-w-0 space-y-2">
    <Label for="example-date">Datum</Label>
    <Input id="example-date" v-model="form.date" type="date" class="date-safe" />
    <InputError :message="form.errors.date" />
</div>
```

- Jedes sichtbare Feld besitzt eine `Label`-Komponente mit passendem `for` und eine eindeutige `id` am Steuerelement.
- Label und Feld werden nicht direkt ohne Abstand nebeneinandergestellt. `space-y-2` verhindert insbesondere, dass Unterlängen wie bei „g“ optisch in die Feldumrandung schneiden.
- Formulargrids starten einspaltig und wechseln üblicherweise mit `sm:grid-cols-2` auf zwei Spalten: `grid gap-5 sm:grid-cols-2`.
- Lange Inhalte wie Mitgliedsauswahl, Beschreibung oder Referenz belegen mit `sm:col-span-2` die volle Formularbreite.
- Eingabefelder verwenden grundsätzlich die gemeinsame `Input`-Komponente. Sie sorgt für `h-9`, Rahmen, Fokuszustand, `min-w-0` und auf Mobilgeräten für `text-base`, wodurch iOS kein unerwünschtes Eingabe-Zoom auslöst.
- Für Textbereiche, Checkboxen, Schalter und Buttons sind ebenfalls die gemeinsamen UI-Komponenten zu verwenden.
- Native `select`-Elemente sind nur für kurze, statische Auswahllisten zulässig. Sie verwenden mindestens `h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm`.
- Pflichtfelder werden konsistent mit `*` an der Beschriftung gekennzeichnet, wenn das Formular Pflichtangaben sichtbar unterscheidet.
- Hinweise stehen unter dem zugehörigen Feld in `text-sm text-muted-foreground`; Validierungsfehler werden über `InputError` ausgegeben.
- Fehler dürfen das Layout nicht überlagern. Für dynamisch erscheinende Fehlermeldungen ausreichend vertikalen Fluss vorsehen.
- Während des Speicherns werden auslösende Buttons deaktiviert und zeigen bei längeren Vorgängen den vorhandenen `Spinner`.

## Datumsfelder und responsive Aktionszeilen

Native Datumsfelder haben in WebKit-basierten iPad-Browsern eine problematische intrinsische Mindestbreite. Das betrifft auch Firefox auf iPadOS. Deshalb gelten diese Regeln ausnahmslos:

- Die gemeinsame `Input`-Komponente ergänzt bei `type="date"` automatisch die globale Klasse `date-safe` aus `resources/css/app.css`. Direkte native Datumsfelder oder dynamisch gesetzte Typen erhalten `date-safe` ausdrücklich.
- Der unmittelbare Feld-Wrapper erhält `min-w-0 space-y-2`.
- Das Grid verwendet flexible Spalten mit `minmax(0, 1fr)` und nicht nur `1fr`, wenn Datumsfelder und Buttons gemeinsam angeordnet werden.
- Datumsfelder und Aktionsbuttons stehen auf kleinen und mittleren Viewports nicht erzwungen in einer einzigen Zeile. Der Button wechselt erst bei ausreichend Platz, üblicherweise ab `xl` oder `2xl`, in dieselbe Reihe.
- Ein Button darf niemals absolut über einem Datumsfeld positioniert werden.
- Keine festen Pixelbreiten für Datumsfelder vergeben.

Bewährtes Muster für zwei Datumsfelder mit Aktion:

```vue
<form
    class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] 2xl:items-end"
>
    <div class="min-w-0 space-y-2">
        <Label for="from">Von</Label>
        <Input id="from" type="date" class="date-safe" />
    </div>
    <div class="min-w-0 space-y-2">
        <Label for="until">Bis</Label>
        <Input id="until" type="date" class="date-safe" />
    </div>
    <Button class="sm:col-span-2 2xl:col-span-1" type="submit">
        Zeitraum anwenden
    </Button>
</form>
```

## Dropdowns und Auswahllisten

- Kataloge mit vielen Einträgen, Länder, Telefonvorwahlen und Mitgliederlisten verwenden `SearchableDropdown`.
- Keine neue Dropdown-Implementierung anlegen, solange `SearchableDropdown` den Anwendungsfall abdeckt.
- Trigger erhalten die gleiche Darstellung wie Eingabefelder: `h-9 w-full rounded-md border border-input bg-background px-3`.
- Jede Dropdown-Instanz braucht verständliche Werte für `placeholder`, `search-placeholder`, `empty-text` und `aria-label`.
- Suchtext soll neben dem sichtbaren Namen auch relevante Kennungen enthalten, etwa Mitgliedsnummern oder Ländercodes.
- Die Auswahl wird über `@update:model-value` in das Formular geschrieben. Touch- und Fokusbehandlung nicht mit eigenen `blur`, `focusout` oder verzögerten Klick-Workarounds überschreiben; die gemeinsame Komponente enthält die iPad-kompatible Logik.
- Dropdowns dürfen nicht in einem Vorfahren liegen, der das Popover durch `overflow-hidden` abschneidet. Bei Tabellenkarten gegebenenfalls nur den Tabellenbereich separat scroll- oder clipbar machen.
- Optionen müssen per Tastatur erreichbar und der aktuelle Wert muss über `aria-selected` erkennbar sein.

## Buttons und Aktionen

- Für alle normalen Aktionen die gemeinsame `Button`-Komponente verwenden.
- Primäraktion: Standardvariante. Sekundäraktion: `outline`. Untergeordnete Aktion: `ghost`. Gefährliche, bestätigte Aktion: `destructive`.
- Buttons enthalten ein dekoratives Lucide-Icon üblicherweise mit `class="size-4"`; ein alleinstehender Icon-Button braucht einen eindeutigen `aria-label`.
- Aktionsgruppen verwenden `flex flex-wrap gap-2` oder `gap-3`. Bei beengtem Platz dürfen Aktionen umbrechen oder unter die Felder wechseln.
- Die wichtigste abschließende Aktion steht optisch zuletzt. Destruktive Aktionen werden nicht direkt neben eine häufig verwendete Primäraktion gequetscht, wenn Fehlbedienung wahrscheinlich ist.
- Deaktivierte Zustände ergeben sich aus der tatsächlichen Fachlogik, nicht nur aus einem laufenden Request.
- Links, die wie Buttons aussehen, verwenden `Button` mit `as-child` oder die vorhandenen Button-Varianten statt kopierter Klassen.

## Alerts, leere Zustände und Rückmeldungen

Semantische Rückmeldungen innerhalb einer Seite verwenden `StatusAlert`:

| Typ       | Verwendung                                                                                |
| --------- | ----------------------------------------------------------------------------------------- |
| `info`    | neutrale Hinweise oder notwendige Zusatzinformation                                       |
| `success` | abgeschlossener Zustand oder positiver leerer Zustand, zum Beispiel keine offenen Anträge |
| `warning` | handlungsrelevanter, aber nicht fehlgeschlagener Zustand                                  |
| `error`   | Validierungs-, Lade- oder Verarbeitungsfehler                                             |

```vue
<StatusAlert type="success" title="Keine offenen Anträge">
    Derzeit liegen keine Anträge zur Bearbeitung vor.
</StatusAlert>
```

- Keine eigenen farbigen Alert-Karten pro Seite bauen.
- Ein Alert hat möglichst einen kurzen Titel und einen erklärenden Satz. Mehrere Fehlermeldungen werden über die `messages`-Property ausgegeben.
- Warnungen und Fehler erhalten automatisch `role="alert"`, neutrale und erfolgreiche Meldungen `role="status"`.
- Toasts sind für kurze Rückmeldungen nach einer Aktion gedacht. Dauerhafte oder entscheidungsrelevante Informationen bleiben als `StatusAlert` im Seiteninhalt sichtbar.
- Ein leerer Tabellenzustand darf als ruhige Tabellenzeile dargestellt werden. Ein fachlich bedeutsamer leerer Arbeitsvorrat wird als `StatusAlert` gezeigt.
- Instanzweite Hinweise, die auf jeder Seite sichtbar sein müssen, etwa der Hinweis auf die öffentliche Demo, stehen als schmale, volle Breite nutzende Leiste über dem Seitenkopf. Das Muster ist `DemoBanner` (`border-b bg-primary px-4 py-2 text-sm text-primary-foreground`); es wird im App-Layout und im öffentlichen Kopf eingebunden und nicht in einzelnen Seiten wiederholt.

## Tabellen und lange Daten

- Tabellen verwenden grundsätzlich `w-full text-sm`.
- Kopfzeilen sind semibold und visuell vom Inhalt getrennt; Zahlen und Geldbeträge werden rechtsbündig dargestellt.
- Tabellen liegen in einer Karte und erhalten bei nicht sinnvoll umbrechbaren Spalten einen inneren `overflow-x-auto`-Bereich.
- Die gesamte Seite oder das App-Layout darf wegen einer Tabelle nicht horizontal scrollen.
- Primärinformationen stehen zuerst. Sekundäre Kennungen werden darunter in `text-xs` oder `text-sm text-muted-foreground` angezeigt.
- Zeilenaktionen bleiben auf Touchgeräten erreichbar und dürfen nicht ausschließlich beim Hover erscheinen.
- Filter stehen oberhalb der Tabelle, brechen responsiv um und verwenden dieselben Feld- und Abstandsregeln wie Formulare.

## Listen mit Zeilenaktionen

Einträge mit mehreren Aktionen pro Zeile, etwa die Zuordnungen in der Mitgliederakte (`resources/js/components/members/MemberAssignments.vue`), folgen diesem Muster:

```vue
<li class="flex flex-wrap items-start gap-x-4 gap-y-2">
    <div class="min-w-0 flex-1 basis-48">
        <!-- Titel, Zeitraum, Notiz -->
    </div>
    <div class="flex flex-wrap gap-1">
        <!-- ghost-Buttons mit Icon und Text, reine Icon-Buttons mit aria-label -->
    </div>
</li>
```

- `basis-48` gibt dem Text eine Mindestbreite. Reicht der Platz nicht, brechen die Aktionen in eine eigene Zeile um, statt den Text zu zerquetschen.
- Zeiträume werden einheitlich über `assignmentPeriod` aus `resources/js/lib/memberFormatting.ts` formuliert („seit …“, „… – …“, „Beginn unbekannt“, bei Ehrungen „am …“).
- Beendete Einträge stehen gesammelt in einem `details`-Element „Frühere anzeigen (n)“ unter den aktuellen.

## Responsive Verhalten

- Standardmäßig eine Spalte; `sm`, `md`, `lg`, `xl` und `2xl` nur einsetzen, wenn der Inhalt am jeweiligen Punkt tatsächlich Platz hat.
- iPad-Ansichten nicht automatisch als Desktop behandeln. Besonders Aktionsleisten mit Datumsfeldern bleiben bis `xl` oder `2xl` gestapelt, wenn intrinsische Browser-Steuerelemente vorkommen.
- In Flex-Containern `flex-wrap` verwenden, wenn Texte, Badges oder Buttons variabel breit sind.
- In Grid- und Flex-Kindern `min-w-0` setzen, sobald lange Texte oder native Formelemente vorkommen.
- Für breite Tabellen oder Kalender ist inneres horizontales Scrollen zulässig. Normale Formulare und Karten dürfen keinen horizontalen Scrollbereich benötigen.
- Wichtige Inhalte dürfen nicht nur durch Hover verfügbar sein.
- Popover und Dropdowns benötigen ausreichend hohen `z-index` und eine viewport-begrenzte Breite, zum Beispiel `max-w-[calc(100vw-2rem)]`.

Mindestens zu prüfen sind:

- 320 bis 390 Pixel breite Mobilansicht,
- iPad Hochformat um 768 Pixel,
- iPad Querformat um 1024 Pixel,
- Desktop ab 1280 Pixel,
- Touch-Auswahl sowie Tastaturbedienung,
- heller und dunkler Modus.

## Icons, Farbe und Barrierefreiheit

- Icons stammen aus `@lucide/vue`; keine uneinheitlichen Unicode-Symbole als Bedienicons verwenden.
- Dekorative Icons erhalten `aria-hidden="true"`, sofern die umgebende Komponente dies nicht bereits übernimmt.
- Icon-only-Aktionen benötigen einen eindeutigen zugänglichen Namen.
- Status darf nie allein durch Farbe vermittelt werden. Text, Titel, Icon oder Badge müssen die Bedeutung zusätzlich tragen.
- Fokuszustände der gemeinsamen Komponenten nicht entfernen oder durch `outline-none` ohne gleichwertigen Ersatz überschreiben.
- Formulare müssen mit Tastatur bedienbar sein. Die visuelle Reihenfolge muss der DOM- und Fokusreihenfolge entsprechen.
- Animationen respektieren über die globale CSS-Regel `prefers-reduced-motion`.

## System-E-Mails

Systemseitig erzeugte HTML-E-Mails verwenden verbindlich die anonyme Blade-Komponente `resources/views/components/mail/layout.blade.php`. Das Layout entspricht den Nachrichten zum Mitgliederzugang und darf nicht in einzelnen Mail-Templates erneut nachgebaut werden. Fachliche Mail-Templates liefern ausschließlich den Inhalt im Slot sowie die benötigten Layout-Properties:

```blade
<x-mail.layout
    :club-name="$clubName"
    :logo-url="$logoUrl"
    :title="$subject"
    :preheader="$summary"
>
    <!-- Inhalt der Nachricht -->
</x-mail.layout>
```

- Pflichtangabe ist `club-name`. `logo-url`, `title` und der in vielen Posteingängen sichtbare Vorschautext `preheader` sind optional; für reguläre Systemnachrichten sollen Titel und Preheader gesetzt werden.
- Das gemeinsame Layout definiert den hellgrauen Seitenhintergrund, die maximal 600 Pixel breite weiße Inhaltskarte, Rahmen und Rundung, den Kopf mit optionalem Vereinslogo und Vereinsnamen sowie Typografie und Innenabstände.
- Das Vereinslogo ist rein dekorativ, weil der Vereinsname unmittelbar daneben beziehungsweise darunter steht. Es verwendet daher einen leeren Alternativtext und bleibt auf maximal 180 × 64 Pixel begrenzt.
- Inhalte beginnen und enden mit kontrollierten Absatzabständen. Der erste Absatz verwendet üblicherweise `margin-top:0`, der letzte `margin-bottom:0`.
- Sekundär- und Hilfstexte verwenden inline `color:#53606d` und `font-size:14px`, sehr kompakte Rechtshinweise höchstens `font-size:13px`.
- Primäre E-Mail-Aktionen verwenden das Muster der Zugangs-Mail: Inline-Link mit `padding:13px 20px`, `border-radius:8px`, `background:#17212b`, weißer Schrift und `font-weight:700`.
- Hervorgehobene Hinweise in E-Mails verwenden die anonyme Blade-Komponente `resources/views/components/mail/alert.blade.php` und keine eigenen farbigen Blöcke. Sie entspricht den Typen von `StatusAlert`: `error` (rot, Standard) für handlungsnotwendige Probleme, etwa eine zu ändernde E-Mail-Adresse, `warning` und `info`. Ein Alert hat einen kurzen `title` und einen erklärenden Satz im Slot und steht vor dem eigentlichen Nachrichtentext:

    ```blade
    <x-mail.alert type="error" title="Bitte ändere deine E-Mail-Adresse">
        Diese Adresse ist für den Mitgliederbereich nicht mehr zugelassen.
    </x-mail.alert>
    ```

- Für die Client-Kompatibilität bleiben E-Mail-Struktur und Layout tabellenbasiert und alle wesentlichen Styles inline. Externe Stylesheets, JavaScript, Webfonts und Anwendungs-CSS-Tokens sind in E-Mails unzulässig. Die festen E-Mail-Farben sind eine bewusste Ausnahme von den Token-Regeln der Weboberfläche.
- Dynamische Inhalte werden standardmäßig durch Blade escaped. Unescaped HTML ist nur für bereits serverseitig bereinigte Inhalte zulässig und muss im Template erkennbar begründet sein.
- Fachliche Anhänge, Betreff, Absender und Versandlogik verbleiben in der jeweiligen `Mailable`; die Layout-Komponente enthält keine Geschäftslogik.
- Abweichende Spezialformate, etwa frei gestaltete Serienmails, dürfen ein eigenes Layout verwenden, müssen aber weiterhin responsiv, tabellenbasiert und ohne externe aktive Inhalte umgesetzt sein.
- Bei Änderungen mindestens das gerenderte HTML prüfen. Relevante Mailable-Tests sollen Vereinsname, Vorschautext beziehungsweise Betreffkontext, fachlichen Inhalt und vorhandene Anhänge abdecken.

## Umsetzung und Qualitätssicherung

Vor Abschluss einer Frontend-Änderung prüfen:

- Wurden vorhandene Komponenten statt lokaler Kopien verwendet?
- Entsprechen Seitenkopf, Karten, Schriftgrößen und Abstände den oben genannten Mustern?
- Besitzt jeder Feld-Wrapper `space-y-2` und jedes Feld eine verknüpfte Beschriftung?
- Werden alle Datumsfelder durch `Input` oder ausdrücklich mit `date-safe` abgesichert und besitzen sie einen `min-w-0`-Wrapper?
- Können Aktionsgruppen umbrechen, ohne Felder zu überdecken?
- Funktionieren Dropdowns per Touch und Tastatur und werden sie nicht abgeschnitten?
- Werden Fehler und Zustände mit `InputError`, `StatusAlert` oder Toasts passend dargestellt?
- Entsteht weder auf iPad-Größe noch mobil horizontaler Seiten-Overflow?
- Sind Dark Mode, Fokuszustände, deaktivierte Zustände und lange Inhalte berücksichtigt?
- Laufen `npm run check`, `npm run types:check` und bei visuellen oder Build-relevanten Änderungen `npm run build` erfolgreich?
