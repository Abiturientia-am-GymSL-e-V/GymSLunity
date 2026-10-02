# Platzhalter

Platzhalter setzen Vereins- und Mitgliedsdaten automatisch in Texte ein. Sie stehen in doppelten geschweiften Klammern ohne Leerzeichen, zum Beispiel `{{verein.name}}`. Dieselbe Angabe hat überall denselben Platzhalter, egal ob in einer Serien-E-Mail, im Impressum oder in einem Formulartext.

Wo Platzhalter erlaubt sind, listet GymSLunity die verfügbaren direkt neben dem Eingabefeld auf. Unbekannte Platzhalter lehnt GymSLunity beim Speichern ab, damit Tippfehler nicht unbemerkt in Dokumenten landen. HTML wird in Texten nicht ausgeführt.

## Wo sie gelten

| Ort                                                                           |       Vereinsdaten        | Mitgliedsdaten |
| ----------------------------------------------------------------------------- | :-----------------------: | :------------: |
| [Serien-E-Mails und Serienbriefe](kommunikation.md)                           |             ✓             |       ✓        |
| [Willkommensmail](konfiguration.md#startseite)                                |             ✓             |       ✓        |
| [Impressum, Datenschutz und übrige E-Mail-Texte](konfiguration.md#startseite) |             ✓             |                |
| [Formulartexte im Selfservice](konfiguration.md#selfservice-und-formulare)    |             ✓             |                |
| [SEPA-Mandatstext](konfiguration.md#buchhaltung)                              | nur Name und Gläubiger-ID |                |

## Vereinsdaten

Die Werte stammen aus [Konfiguration → Vereinsdaten](konfiguration.md#vereinsdaten).

| Platzhalter                          | Inhalt                                                                                      |
| ------------------------------------ | ------------------------------------------------------------------------------------------- |
| `{{verein.name}}`                    | vollständiger Vereinsname                                                                   |
| `{{verein.short_name}}`              | Kurzname                                                                                    |
| `{{verein.founded_at}}`              | Gründungsdatum                                                                              |
| `{{verein.street}}`                  | Straße und Hausnummer                                                                       |
| `{{verein.postal_code}}`             | Postleitzahl                                                                                |
| `{{verein.city}}`                    | Ort                                                                                         |
| `{{verein.country}}`                 | Land                                                                                        |
| `{{verein.email}}`                   | E-Mail-Adresse                                                                              |
| `{{verein.phone}}`                   | Telefon                                                                                     |
| `{{verein.website}}`                 | Website                                                                                     |
| `{{verein.register_number}}`         | Vereinsregisternummer                                                                       |
| `{{verein.register_court}}`          | Registergericht                                                                             |
| `{{verein.tax_number}}`              | Steuernummer                                                                                |
| `{{verein.tax_office}}`              | Finanzamt                                                                                   |
| `{{verein.vat_id}}`                  | Umsatzsteuer-ID                                                                             |
| `{{verein.is_nonprofit}}`            | gemeinnützig (Ja/Nein)                                                                      |
| `{{verein.representatives}}`         | vertretungsberechtigter Vorstand                                                            |
| `{{verein.content_responsible}}`     | inhaltlich verantwortliche Person                                                           |
| `{{verein.data_protection_officer}}` | Datenschutzbeauftragte Person                                                               |
| `{{verein.supervisory_authority}}`   | Datenschutz-Aufsichtsbehörde                                                                |
| `{{verein.hosting_provider}}`        | Hosting-Anbieter                                                                            |
| `{{verein.account_holder}}`          | Kontoinhaber                                                                                |
| `{{verein.iban}}`                    | IBAN                                                                                        |
| `{{verein.bic}}`                     | BIC                                                                                         |
| `{{verein.bank_name}}`               | Kreditinstitut                                                                              |
| `{{verein.glaeubiger_id}}`           | SEPA-Gläubiger-ID                                                                           |
| `{{verein.vorstand}}`                | aktuelle Inhaber der Vorstandsämter, aus [Vorstand / Ämter](aemter-abteilungen-ehrungen.md) |

In Formulartexten, rechtlichen Seiten und E-Mail-Texten der Konfiguration stehen zusätzlich die steuerlichen Angaben aus [Konfiguration → Spenden](konfiguration.md#spenden) zur Verfügung:

| Platzhalter                                | Inhalt                                                                           |
| ------------------------------------------ | -------------------------------------------------------------------------------- |
| `{{verein.tax_privilege_notice}}`          | Art des Bescheids, etwa „Freistellungsbescheid (Veranlagungszeitraum 2023–2025)“ |
| `{{verein.tax_privilege_notice_date}}`     | Datum des Bescheids                                                              |
| `{{verein.tax_privilege_notice_location}}` | Ausstellungsort des Bescheids, i. d. R. Sitz des Finanzamtes                     |
| `{{verein.donation_purposes}}`             | die steuerbegünstigten Zwecke                                                    |
| `{{verein.deductible_scope}}`              | „Spenden“ oder „Spenden und Mitgliedsbeiträge“                                   |

Im **SEPA-Mandatstext** stehen nur `{{verein.name}}` und `{{verein.glaeubiger_id}}` zur Verfügung.

Die Gläubiger-ID hieß früher `{{verein.creditor_id}}`. Gespeicherte Texte mit dem alten Namen funktionieren weiter. Verwende für neue Texte `{{verein.glaeubiger_id}}`.

## Mitgliedsdaten

| Platzhalter                    | Inhalt                                                                                    |
| ------------------------------ | ----------------------------------------------------------------------------------------- |
| `{{mitglied.anrede}}`          | „Frau“ oder „Herr“, bei anderen Angaben leer                                              |
| `{{mitglied.briefanrede}}`     | „Sehr geehrte Frau Albers“, „Sehr geehrter Herr Brandt“ oder „Guten Tag Vorname Nachname“ |
| `{{mitglied.name}}`            | vollständiger Name                                                                        |
| `{{mitglied.mitgliedsnummer}}` | Mitgliedsnummer                                                                           |
| `{{mitglied.adresse}}`         | mehrzeilige Anschrift, passend für das Adressfeld eines Briefs                            |
| `{{datum.heute}}`              | heutiges Datum, zum Beispiel 01.10.2026                                                   |

Dazu kommen die einzelnen Mitgliedsfelder als `{{mitglied.<Feldschlüssel>}}`, etwa `{{mitglied.first_name}}`, `{{mitglied.city}}`, `{{mitglied.membership_type}}` oder `{{mitglied.joined_at}}`, sowie alle eigenen Felder. Bei Abteilungen, Ämtern und Ehrungen steht dort die heute gültige Auswahl. Die vollständige Liste zeigt GymSLunity neben dem Editor. Bankdaten und andere sensible Felder sind als Platzhalter bewusst nicht verfügbar.

## Beispiel

```text
{{mitglied.adresse}}

{{verein.city}}, {{datum.heute}}

Einladung zur Mitgliederversammlung

{{mitglied.briefanrede}},

der Vorstand des {{verein.name}} lädt dich herzlich ein …

Mit sportlichen Grüßen
{{verein.vorstand}}
```
