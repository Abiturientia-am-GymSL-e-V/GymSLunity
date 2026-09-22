# Referenzdaten

`countries.json` enthält ISO-3166-1-Alpha-2-Codes aus der lokal installierten iso-codes-Datenbank (`/usr/share/iso-codes/json/iso_3166-1.json`). Die deutschen Anzeigenamen wurden mit `Intl.DisplayNames(["de"], { type: "region" })` unter Node 24 erzeugt. Stand: 22.09.2026. Diese Datei ist eine lokale Eingabehilfe; eine Länderabfrage bei einem externen Dienst findet nicht statt.

Herkunft: [iso-codes](https://salsa.debian.org/iso-codes-team/iso-codes), [Unicode CLDR](https://cldr.unicode.org/). Die Postleitzahl-Zuordnung und deren GeoNames-Lizenz sind separat unter [postal/README.md](postal/README.md) dokumentiert.
