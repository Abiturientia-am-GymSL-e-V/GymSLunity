<?php

namespace App\PublicSite;

use App\SelfService\FormTemplates;

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

Vertreten durch den Vorstand.

Kontakt
Telefon: {{verein.phone}}
E-Mail: {{verein.email}}

Registereintrag
Registergericht: {{verein.register_court}}
Registernummer: {{verein.register_number}}

Umsatzsteuer-ID
Umsatzsteuer-Identifikationsnummer gemäß § 27 a Umsatzsteuergesetz: {{verein.vat_id}}

Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV ist der vertretungsberechtigte Vorstand unter der oben genannten Anschrift.
TEXT,
            'privacy_text' => <<<'TEXT'
Datenschutzerklärung

1. Verantwortlicher
Verantwortlich für die Datenverarbeitung auf dieser Website ist:

{{verein.name}}
{{verein.street}}
{{verein.postal_code}} {{verein.city}}
E-Mail: {{verein.email}}
Telefon: {{verein.phone}}

2. Verarbeitung beim Besuch der Website
Beim Aufruf dieser Website verarbeitet der Server technisch erforderliche Verbindungsdaten, insbesondere IP-Adresse, Zeitpunkt, angeforderte Seite, Referrer sowie Browser- und Betriebssystemangaben. Die Verarbeitung dient dem sicheren und zuverlässigen Betrieb der Website.

3. Mitgliederzugang und Online-Beitritt
Die von dir eingegebenen Daten werden zur Prüfung deiner Berechtigung, zur Bereitstellung des Mitgliederbereichs sowie – beim Online-Beitritt – zur Bearbeitung deines Mitgliedsantrags verarbeitet. Rechtsgrundlagen sind Art. 6 Abs. 1 lit. b DSGVO und die berechtigten Interessen des Vereins gemäß Art. 6 Abs. 1 lit. f DSGVO.

4. E-Mail-Versand
Für Zugangs- und Bestätigungsnachrichten wird deine E-Mail-Adresse verarbeitet. Einmalige Zugangslinks sind zeitlich begrenzt und nur einmal verwendbar.

5. Speicherdauer und Empfänger
Personenbezogene Daten werden nur so lange gespeichert, wie dies für den jeweiligen Zweck oder aufgrund gesetzlicher Pflichten erforderlich ist. Eine Weitergabe erfolgt nur, soweit sie für den Betrieb, die Vertragserfüllung oder aufgrund gesetzlicher Vorgaben erforderlich ist.

6. Deine Rechte
Du hast nach Maßgabe der DSGVO insbesondere Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch. Außerdem kannst du dich bei einer Datenschutzaufsichtsbehörde beschweren.

7. Kontakt
Fragen zum Datenschutz richtest du bitte an {{verein.email}}.
TEXT,
            'member_access_mail_subject' => 'Dein Zugang zum Mitgliederbereich von {{verein.name}}',
            'member_access_mail_text' => "Hallo,\n\ndu hast einen einmaligen Zugang zum Mitgliederbereich von {{verein.name}} angefordert. Bestätige den Zugang über die Schaltfläche in dieser E-Mail.",
            'join_mail_subject' => 'E-Mail-Adresse für deinen Beitritt bei {{verein.name}} bestätigen',
            'join_mail_text' => "Hallo,\n\ndu möchtest Mitglied bei {{verein.name}} werden. Bestätige zunächst deine E-Mail-Adresse über die Schaltfläche in dieser E-Mail. Anschließend kannst du deinen Mitgliedsantrag ausfüllen.",
            'welcome_mail_subject' => 'Willkommen bei {{verein.name}}',
            'welcome_mail_text' => "Hallo,\n\nvielen Dank für deinen Mitgliedsantrag bei {{verein.name}}. Deinen digital eingereichten Antrag findest du als PDF im Anhang.\n\nWir melden uns, falls noch etwas zu klären ist.",
            'contribution_invoice_mail_subject' => 'Deine Beitragsrechnung von {{verein.name}}',
            'contribution_invoice_mail_text' => "Hallo,\n\nim Anhang erhältst du deine Beitragsrechnung von {{verein.name}} als PDF. Bitte beachte das dort angegebene Fälligkeitsdatum und den Zahlungsweg.\n\nBei Rückfragen wende dich bitte an die Vereinsverwaltung.",
        ];
    }

    /** @return list<string> */
    public static function placeholders(): array
    {
        return FormTemplates::placeholders();
    }

    public static function validate(string $text): void
    {
        FormTemplates::validate($text);
    }

    /** @param array<string, mixed> $data */
    public static function render(string $key, array $data): string
    {
        return FormTemplates::renderText((string) ($data[$key] ?? self::defaults()[$key]), $data);
    }
}
