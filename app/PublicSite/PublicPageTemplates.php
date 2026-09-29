<?php

declare(strict_types=1);

namespace App\PublicSite;

use App\SelfService\FormTemplates;
use App\Support\FormOfAddress;

final class PublicPageTemplates
{
    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'imprint_text' => <<<'TEXT'
Angaben gemäß § 5 DDG

{{verein.name}}
{{verein.street}}
{{verein.postal_code}} {{verein.city}}
{{verein.country}}

Vertretungsberechtigt: {{verein.representatives}}

Kontakt
Telefon: {{verein.phone}}
E-Mail: {{verein.email}}
Website: {{verein.website}}

Registergericht: {{verein.register_court}}
Registernummer: {{verein.register_number}}
Umsatzsteuer-Identifikationsnummer gemäß § 27a UStG: {{verein.vat_id}}

Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV: {{verein.content_responsible}}, Anschrift wie oben

Verbraucherstreitbeilegung
Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.
TEXT,
            'privacy_text' => <<<'TEXT'
Datenschutzerklärung

1. Verantwortlicher
Verantwortlich für die Verarbeitung personenbezogener Daten auf dieser Website und in der Mitgliederverwaltung ist:

{{verein.name}}
{{verein.street}}
{{verein.postal_code}} {{verein.city}}
Vertreten durch: {{verein.representatives}}
E-Mail: {{verein.email}}
Telefon: {{verein.phone}}

Datenschutzbeauftragte Person: {{verein.data_protection_officer}}

2. Aufruf der Website und Server-Protokolle
Beim Aufruf dieser Website verarbeitet der Server technisch erforderliche Verbindungsdaten: IP-Adresse, Zeitpunkt, angeforderte Seite, Referrer sowie Browser- und Betriebssystemangaben. Die Verarbeitung dient der Auslieferung der Website, ihrer Sicherheit und der Abwehr von Angriffen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO; unser berechtigtes Interesse ist ein sicherer und stabiler Betrieb. Protokolldaten werden gelöscht, sobald sie dafür nicht mehr erforderlich sind, in der Regel spätestens nach 30 Tagen. Für Sicherheitsereignisse wie Anmeldungen speichern wir IP-Adressen nur als nicht umkehrbaren Prüfwert.

3. Cookies
Wir setzen nur technisch notwendige Cookies ein: ein Sitzungs-Cookie für die Anmeldung und ein Cookie zum Schutz von Formularen vor Missbrauch (CSRF). Sie werden mit dem Ende der Sitzung bzw. nach kurzer Zeit ungültig. Rechtsgrundlage ist § 25 Abs. 2 Nr. 2 TDDDG in Verbindung mit Art. 6 Abs. 1 lit. f DSGVO. Analyse- oder Werbe-Cookies und Tracking-Dienste setzen wir nicht ein.

4. Mitgliedschaft und Mitgliederverwaltung
Wir verarbeiten die Daten unserer Mitglieder, die für die Begründung und Durchführung der Mitgliedschaft nach der Satzung erforderlich sind, zum Beispiel Name, Anschrift, Kontaktdaten, Geburtsdatum, Eintritts- und Austrittsdaten, Mitgliedsart und Beiträge. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO. Freiwillige Angaben verarbeiten wir auf Grundlage einer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die jederzeit mit Wirkung für die Zukunft widerrufen werden kann. Ohne die für die Mitgliedschaft erforderlichen Angaben ist eine Aufnahme nicht möglich.

5. Beiträge, SEPA-Lastschriften und Rechnungen
Für den Beitragseinzug verarbeiten wir Bankverbindung, Mandatsreferenz und Zahlungsinformationen und übermitteln sie an unser Kreditinstitut. Bankverbindungen werden verschlüsselt gespeichert. Rechtsgrundlagen sind Art. 6 Abs. 1 lit. b DSGVO sowie für die Aufbewahrung von Buchungsbelegen Art. 6 Abs. 1 lit. c DSGVO in Verbindung mit § 147 AO und § 257 HGB.

6. Spenden und Zuwendungsbestätigungen
Für Spenden verarbeiten wir Name, Anschrift, Betrag und Datum, um die Spende zu verbuchen und eine Zuwendungsbestätigung auszustellen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. c DSGVO in Verbindung mit § 50 EStDV und § 147 AO.

7. Mitgliederportal, Online-Beitritt und E-Mail-Versand
Im Mitgliederportal verarbeiten wir die dort angezeigten und geänderten Angaben sowie – beim Online-Beitritt – die Daten des Mitgliedsantrags. Für die Anmeldung senden wir einmalige, zeitlich begrenzte Zugangslinks an die hinterlegte E-Mail-Adresse. Wer einen Passkey einrichtet, speichert bei uns nur dessen öffentlichen Schlüssel; biometrische Daten verlassen das eigene Gerät nicht. Vereinsinformationen versenden wir per E-Mail an Mitglieder. Rechtsgrundlagen sind Art. 6 Abs. 1 lit. b und f DSGVO.

8. Empfänger und Auftragsverarbeitung
Zugriff auf die Daten haben nur die Personen im Verein, die sie für ihre Aufgabe benötigen.
Hosting der Website und der Mitgliederverwaltung: {{verein.hosting_provider}}
Für den E-Mail-Versand nutzen wir einen E-Mail-Dienstleister. Mit Dienstleistern, die Daten in unserem Auftrag verarbeiten, bestehen Verträge nach Art. 28 DSGVO. Darüber hinaus geben wir Daten nur weiter, soweit dies zur Erfüllung der Mitgliedschaft erforderlich ist (zum Beispiel an Kreditinstitute) oder wir gesetzlich dazu verpflichtet sind (zum Beispiel an Finanzbehörden). Eine Übermittlung in Staaten außerhalb der EU bzw. des EWR ist nicht vorgesehen.

9. Speicherdauer
Personenbezogene Daten löschen wir, sobald der Zweck entfällt. Mitgliedsdaten werden nach dem Ende der Mitgliedschaft gelöscht, soweit keine gesetzlichen Aufbewahrungspflichten bestehen. Buchungs- und Spendenbelege bewahren wir nach § 147 AO und § 257 HGB bis zu zehn Jahre auf.

10. Deine Rechte
Du hast das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18) und Datenübertragbarkeit (Art. 20). Eine erteilte Einwilligung kannst du jederzeit mit Wirkung für die Zukunft widerrufen (Art. 7 Abs. 3 DSGVO).

Widerspruchsrecht: Soweit wir Daten auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO verarbeiten, kannst du aus Gründen, die sich aus deiner besonderen Situation ergeben, jederzeit widersprechen (Art. 21 DSGVO).

Du kannst dich außerdem bei einer Datenschutz-Aufsichtsbehörde beschweren (Art. 77 DSGVO).
Für uns zuständige Aufsichtsbehörde: {{verein.supervisory_authority}}

Eine automatisierte Entscheidungsfindung einschließlich Profiling findet nicht statt.

11. Kontakt
Fragen zum Datenschutz richtest du bitte an {{verein.email}}.
TEXT,
            'member_access_mail_subject' => 'Dein Zugang zum Mitgliederbereich von {{verein.name}}',
            'member_access_mail_text' => "Hallo,\n\ndu hast einen einmaligen Zugang zum Mitgliederbereich von {{verein.name}} angefordert. Bestätige den Zugang über die Schaltfläche in dieser E-Mail.",
            'join_mail_subject' => 'E-Mail-Adresse für deinen Beitritt bei {{verein.name}} bestätigen',
            'join_mail_text' => "Hallo,\n\ndu möchtest Mitglied bei {{verein.name}} werden. Bestätige zunächst deine E-Mail-Adresse über die Schaltfläche in dieser E-Mail. Anschließend kannst du deinen Mitgliedsantrag ausfüllen.",
            'welcome_mail_subject' => 'Willkommen bei {{verein.name}}',
            'welcome_mail_text' => "Hallo,\n\nvielen Dank für deinen Mitgliedsantrag bei {{verein.name}}. Deinen digital eingereichten Antrag findest du als PDF im Anhang.\n\nWir melden uns, falls noch etwas zu klären ist.",
            'member_welcome_mail_subject' => 'Willkommen im Mitgliederbereich von {{verein.name}}',
            'member_welcome_mail_text' => FormOfAddress::choose(
                "Hallo {{mitglied.name}},\n\nwillkommen bei {{verein.name}}! Im Mitgliederbereich kannst du deine Daten einsehen und aktualisieren, deine Dokumente abrufen und weitere Angebote des Vereins nutzen.\n\nSo meldest du dich an:\n1. Öffne den Mitgliederbereich über die Schaltfläche in dieser E-Mail.\n2. Gib deine unten genannte E-Mail-Adresse ein. Nutzen mehrere Personen dieselbe Adresse, gib zusätzlich deine Mitgliedsnummer an.\n3. Du erhältst einen einmaligen Anmeldelink per E-Mail. Ein Passwort brauchst du nicht.\n\nNach der Anmeldung kannst du einen Passkey einrichten und dich damit künftig ohne E-Mail-Link anmelden.",
                "Guten Tag {{mitglied.name}},\n\nwillkommen bei {{verein.name}}! Im Mitgliederbereich können Sie Ihre Daten einsehen und aktualisieren, Ihre Dokumente abrufen und weitere Angebote des Vereins nutzen.\n\nSo melden Sie sich an:\n1. Öffnen Sie den Mitgliederbereich über die Schaltfläche in dieser E-Mail.\n2. Geben Sie Ihre unten genannte E-Mail-Adresse ein. Nutzen mehrere Personen dieselbe Adresse, geben Sie zusätzlich Ihre Mitgliedsnummer an.\n3. Sie erhalten einen einmaligen Anmeldelink per E-Mail. Ein Passwort brauchen Sie nicht.\n\nNach der Anmeldung können Sie einen Passkey einrichten und sich damit künftig ohne E-Mail-Link anmelden.",
            ),
            'contribution_invoice_mail_subject' => 'Deine Beitragsrechnung von {{verein.name}}',
            'contribution_invoice_mail_text' => "Hallo,\n\nim Anhang erhältst du deine Beitragsrechnung von {{verein.name}} als PDF. Bitte beachte das dort angegebene Fälligkeitsdatum und den Zahlungsweg.\n\nBei Rückfragen wende dich bitte an den Vorstand.",
        ];
    }

    /** Mail texts addressed to one member; they also accept {{mitglied.*}} placeholders. */
    public const MEMBER_TEMPLATES = ['member_welcome_mail_subject', 'member_welcome_mail_text'];

    /** @return list<string> */
    public static function placeholders(): array
    {
        return FormTemplates::placeholders();
    }

    public static function validate(string $text): void
    {
        FormTemplates::validate($text);
    }

    /** Pages whose lines are dropped when all of their placeholders are empty. */
    private const LEGAL_PAGES = ['imprint_text', 'privacy_text'];

    /** @param array<string, mixed> $data */
    public static function render(string $key, array $data): string
    {
        $text = (string) ($data[$key] ?? self::defaults()[$key]);
        if (in_array($key, self::LEGAL_PAGES, true)) {
            $text = self::withoutEmptyLines($text, $data);
        }

        return FormTemplates::renderText($text, $data);
    }

    /**
     * Removes lines like "Registernummer: {{verein.register_number}}" when the
     * club has not entered the value, so optional details do not appear as
     * empty labels. Lines without placeholders are kept.
     *
     * @param  array<string, mixed>  $data
     */
    private static function withoutEmptyLines(string $text, array $data): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $kept = array_filter($lines, function (string $line) use ($data): bool {
            if (preg_match_all('/\{\{verein\.([a-z0-9_]+)\}\}/', $line, $matches) === 0) {
                return true;
            }

            return trim(FormTemplates::renderText(implode('', $matches[0]), $data)) !== '';
        });

        return (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept));
    }
}
