# Changelog

## 1.23 - 2026-11-24

- Login-Schutz fuer gemeinsame Schulnetze: normale Anmeldungen werden pro Konto, Kiosk-PIN-Pruefungen pro signierter Browserkennung begrenzt. Die bisherigen niedrigen IP-Sperren und die globale Kiosk-Sperre entfallen.
- Getrennte, im Adminbereich einstellbare Kurzzeitlimits gegen automatisierte Anfragen: standardmaessig 600 Logins und 120 Kiosk-Pruefungen je IP innerhalb von 60 Sekunden. Weitere Anfragen verlaengern die Wartezeit nicht.
- Abgelaufene Sperren geben wieder die volle Versuchszahl frei. Parallele Anfragen werden atomar vor der eigentlichen Passwort-/PIN-Pruefung gezaehlt; erfolgreiche Anmeldungen setzen nur ihren eigenen Zaehler zurueck.
- Alte gemeinsame Sperreintraege werden automatisch entfernt; Kontosperren, PINs, Kiosk-Freischaltungen und Stammdaten bleiben erhalten.
- Praezisere Sperrmeldungen, Erläuterungen im Adminbereich und Integrationstests fuer gemeinsame IPs, manipulierte Cookies, Sperrablauf und parallele Anfragen.

## 1.22 - 2026-11-24

- Optionaler automatischer Tagesreset mit `cron/reset_daily.php` (nur PHP-CLI), Vorschau per `--dry-run`, Exit-Codes und Ausgabe fuer das Cron-Protokoll.
- Gemeinsamer transaktionaler Reset fuer Adminseite und Cron: Schueler:innen ausbuchen und anwesend setzen; Lehrkraeftestandorte und verantwortliche Klassen zuruecksetzen.
- Audit- und Live-Ereignisse werden mit dem Reset gemeinsam gespeichert. Cron-Aufrufe werden im Ereignisprotokoll als automatischer Tagesreset angezeigt.
- Anleitung fuer taegliche Ausfuehrung um 03:00 Uhr, Dateianordnung, Zeitzone und nicht oeffentliches Logverzeichnis.
- Kiosk-PINs, Zugangsdaten, Klassenraeume und Stammdaten bleiben unveraendert.
