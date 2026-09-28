# Lösch-, Anonymisierungs- und Aufbewahrungskonzept

```Hinweis
Die Konfiguration von Aufbewahrungsfristen, Anonymisierung und Löschung personenbezogener Daten ist in Arbeit.
```

Dieses Dokument beschreibt die technische Voreinstellung. Der betreibende Verein muss die Fristen anhand seiner Satzung, Einwilligungen sowie steuer-, handels- und vereinsrechtlichen Pflichten prüfen und dokumentieren. GymSLunity ersetzt keine Rechtsberatung.

## Datenklassen und voreingestellte Behandlung

| Datenklasse                                    | Zweck                                    | Technische Voreinstellung                                                                                |
| ---------------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Aktive Sitzungen                               | Authentifizierung                        | Ende nach 30 Minuten Inaktivität; jederzeit unter **Einstellungen → Sicherheit** widerrufbar             |
| Passwort-Reset- und Selfservice-Tokens         | Einmaliger Kontozugang                   | Löschung nach Ablauf                                                                                     |
| Sicherheits-Audit-Ereignisse                   | Erkennung und Nachweis von Missbrauch    | 730 Tage; Ereignisse sind bis zur automatischen Löschung unveränderlich                                  |
| Gehashte IP-/Gerätekennungen im Audit-Log      | Ereigniskorrelation                      | Anonymisierung nach 90 Tagen                                                                             |
| Benutzerkonten                                 | Zugriff der Beschäftigten/Ehrenamtlichen | Löschung auf ausdrückliche Aktion; Audit-Verweise werden dabei entkoppelt                                |
| Mitglieds-, Beitrags-, Spenden- und Belegdaten | Vereinsverwaltung und Nachweispflichten  | Keine pauschale automatische Löschung, da die notwendige Frist vom Vorgang abhängt                       |
| Hochgeladene Mitgliedsdokumente                | Antrag/SEPA-Nachweis                     | Verschlüsselt und integritätsgesichert; Löschung zusammen mit dem fachlichen Vorgang nach Vereinsvorgabe |

## Betriebsprozess

`php artisan security:prune` setzt die technischen Fristen um. Der Scheduler führt den Befehl täglich aus; `--dry-run` zeigt vorab die betroffenen Mengen. Backups müssen in das gleiche Fristen- und Löschkonzept einbezogen werden.

Der `APP_KEY` ist getrennt und sicher zu sichern: Ohne ihn können verschlüsselte Dokumente nicht wiederhergestellt werden. Ein Schlüsselwechsel benötigt deshalb eine geplante Neuverschlüsselung der vorhandenen Daten.

Vor einer Löschung fachlicher Daten ist zu prüfen, ob gesetzliche Aufbewahrungspflichten, laufende Forderungen oder Rechtsansprüche entgegenstehen. Ist eine Löschung noch nicht möglich, sollen nicht mehr benötigte direkte Identifikatoren minimiert oder anonymisiert und Zugriffsrechte eingeschränkt werden. Nach Ablauf aller Pflichten ist der gesamte Datensatz einschließlich Dokumenten, Exportkopien und später auch der Backups zu entfernen.

Exporte enthalten personenbezogene Daten. Sie sind nur zweckgebunden, verschlüsselt und mit restriktiven Zugriffsrechten abzulegen und nach Wegfall des Zwecks zu löschen. Die Anwendung liefert Exporte mit `no-store`, protokolliert ihre Erzeugung und begrenzt die Abrufrate; für die außerhalb von GymSLunity gespeicherte Kopie ist der Benutzer verantwortlich.
