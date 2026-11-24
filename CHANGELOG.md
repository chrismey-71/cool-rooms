# Changelog

## 1.22 - 2026-11-24

- Optionaler automatischer Tagesreset mit `cron/reset_daily.php` (nur PHP-CLI), Vorschau per `--dry-run`, Exit-Codes und Ausgabe fuer das Cron-Protokoll.
- Gemeinsamer transaktionaler Reset fuer Adminseite und Cron: Schueler:innen ausbuchen und anwesend setzen; Lehrkraeftestandorte und verantwortliche Klassen zuruecksetzen.
- Audit- und Live-Ereignisse werden mit dem Reset gemeinsam gespeichert. Cron-Aufrufe werden im Ereignisprotokoll als automatischer Tagesreset angezeigt.
- Anleitung fuer taegliche Ausfuehrung um 03:00 Uhr, Dateianordnung, Zeitzone und nicht oeffentliches Logverzeichnis.
- Kiosk-PINs, Zugangsdaten, Klassenraeume und Stammdaten bleiben unveraendert.
