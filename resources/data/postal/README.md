# Postleitzahl-Ortszuordnung

Quelle: [GeoNames Postal Codes](https://download.geonames.org/export/zip/) ([Lizenzhinweise](https://download.geonames.org/export/zip/readme.txt)). Lizenz: CC BY 4.0.

DE.zip abgerufen am 2026-09-21T22:55:23.402909+00:00; SHA-256: `204dd79d1d0de2e92e50777369a723a88bcd354f8a224a090ad118d7a2182942`. Aus den Spalten Postleitzahl und Ortsname wurde eine deduplizierte JSON-Zuordnung erzeugt. Keine Adressdaten von Mitgliedern werden an GeoNames gesendet.

Die Zuordnung ist eine Eingabehilfe, kein verbindliches Adressverzeichnis. Manuelle Ortsangaben bleiben möglich. Aktualisierung: DE.zip herunterladen, DE.txt als TSV lesen und nach Spalte 2 (PLZ) gruppierte eindeutige Werte aus Spalte 3 (Ort) nach DE.json schreiben. Führende Nullen erhalten.
