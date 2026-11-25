# Changelog

## 1.30 - 2026-11-25

- Mobile Raumwahl für Schüler:innen: kompakte Zweispalten-Kacheln mit großen Raumnamen, dezente farbliche Bereichskennzeichnung, Suche nach Raum/Bereich sowie kombinierbare Bereichs- und Freiplatzfilter. Aktueller Raum und Klassenraum bleiben markiert. Direkte Buchung jetzt auch ohne JavaScript; alternatives Auswahlmenü platzsparend eingeklappt. Buchungsprüfung, Admin-Reihenfolge und Kiosk-Rückkehr unverändert.

- Gruppenwechsel in der kompakten Lehrkraftansicht immer sichtbar, auch bei aktiver Stundenplanauswahl. Kleine Tasten für Stundenplan, ganze Klasse und einzelne Gruppen; manuelle Auswahl bleibt bei Formularaktionen und Live-Verbindung erhalten. Nur Gruppen der verantwortlichen Klasse sind auswählbar.

- Kompakte Lehrkraft-Klassenübersicht: Username, Gruppenspalte und sichtbare Zeitspalte entfernt; Gruppe im Kopf, Raumstand weiterhin im Tooltip. Mobile Namens-/Raumzeilen statt hoher Tabellenkarten, Anwesenheit direkt per Statustaste ändern. Sammelaktionen standardmäßig eingeklappt, Namenssuche, Live-Zähler und einmalige Wiederherstellung von Suche/Scrollposition nach Anwesenheitsänderungen. Gruppen- und CSRF-Prüfungen bleiben unverändert.

- Klassengruppen mit frei wählbaren Namen, eindeutigen WebUntis-Kürzeln und Schüler:innenzuordnung durch die Administration. Verwaltung direkt je Klasse und Gruppenauswahl im Schüler:innenformular.
- Lehrkraft-Klassenansicht automatisch auf die Teilgruppen des laufenden oder ausdrücklich übernommenen Stundenplantermins begrenzt; alle Gruppen eines Termins ergeben die ganze Klasse. Ohne Stundenplannutzung ganze Klasse mit manuellem Gruppenfilter. Live-Daten und Klassenaktionen berücksichtigen dieselbe Auswahl; veraltete Formulare werden abgewiesen.
- Gruppen bleiben beim Umbenennen der Klasse erhalten; Klassenwechsel übernehmen keine fremde Gruppe. Gruppenlöschung lässt Schüler:innen bestehen, Klassenlöschung entfernt die zugehörigen Gruppen. Tagesreset behält die Gruppenmitgliedschaften bei. Parser-, Datenbank- und Formularprüfungen ergänzt.

- Modernisiertes CR-Logo mit feinerer Raumkontur, kompakter Stecknadel, ruhigem Gruenverlauf und korallfarbenem Standortpunkt. Alle PNG-App-Icons, iPhone-Icon und Favicon neu erzeugt; maskierbares und iPhone-Icon mit vollflaechigem Hintergrund. Eigene Icon-Cache-Kennung bei unveraenderter App-Version.

- Neutrales Webseitenlogo mit CR-Raumkonturen, offenem Zugang und Standortpunkt; bestehender Hintergrund und weiße Stecknadel unverändert. Logo auf der Projektseite entsprechend angeglichen.

- Kompakte Stundenplan-Einstiegszeile vor dem ersten Import. Mobile Navigation öffnet als separates Menüfenster unter gleichbleibend hohem Kopfbereich, mit Escape-, Außenklick- und Fokuswechsel-Schließen sowie unveränderten Rollen-/Kiosk-Funktionen.

- Dashboard-Stundenplan mit Tagespfeilen und verschiebbarer Drei-Stunden-Ansicht; gemeinsame Beschriftung „Zuordnen:“ vor den kleinen Raum-/Klassen-/Beides-Tasten.
- Optionaler täglicher WebUntis-Abruf über `cron/sync_timetables.php` mit `public_html`-/`www`-Erkennung, Vorschau, separater persönlicher Abschaltung, Parallelaufrufschutz und Erhalt bestehender Kalender bei Abruffehlern oder gleichzeitigen Änderungen. Datei-Upload deaktiviert den automatischen Abruf. Keine automatischen Raum- oder Klassenbuchungen. Anleitung für 07:00 Uhr und Integrationstests ergänzt.

- Stundenplan-Einstieg ohne Import mit geöffneter Anleitung oben und standardmäßig aktiver Vorauswahl; gespeicherte Deaktivierungen bleiben erhalten. Nach dem Import kompakte Dashboard-Kacheln mit kleinen Übernahmetasten, maximal drei Terminen und seitlichem Scrollen auf Mobilgeräten.

- Altformat-Korrektur auch für den allerersten Import ohne gespeichertes Lehrkraftfeld; zentrale Lehrkraftkürzel werden zusätzlich zur Erkennung herangezogen. Sichtbare Kennung der Importkorrektur im Adminbereich.

- Alte Fehlklassifizierungen werden auch beim Lesen gespeicherter Kalender korrigiert. Die zentrale Maske sortiert erkannte Lehrkraftkürzel aus alten Klassen-Zuordnungen in den Lehrkraftbereich um, ohne falsche Zielkonten zu übernehmen. Originaldaten und Konfiguration bleiben bis zur ausdrücklichen Speicherung unverändert.

- WebUntis-Beschreibungen korrekt in Klassen- und Lehrkraftlisten getrennt, einschließlich reiner Lehrkrafttermine und Sammelterminen mit mehreren Lehrkräften. Ältere Importe werden zum erneuten Einlesen gekennzeichnet.
- Bei Lehrer:innenzuordnungen steht links ausdrücklich das WebUntis-Lehrkraftkürzel und rechts der Personenname in COOL Rooms statt eines erneut angehängten Benutzerkürzels.

- Eigene WebUntis-Zuordnungskachel im Admin-Dashboard mit direkten Einstiegen für Räume, Klassen und Lehrer:innen. Reine Admins erhalten keinen persönlichen Stundenplan.
- Inaktive Räume sind in der zentralen Zuordnung sichtbar und speicherbar, bleiben aber für Standortübernahmen gesperrt, bis sie aktiviert werden.

- Zentrale Admin-Zuordnung von WebUntis-Raum-, Klassen-/Teilgruppen- und Lehrkraftkürzeln, mit automatischer Verwendung durch persönliche Stundenpläne.
- Dezente Dashboard-Tasten für Raum, Klasse oder gemeinsame Übernahme; Lehrkraftseite auf Tagesübersicht und aufklappbare Einstellungen reduziert.
- WebUntis-iCal-Anleitung mit Screenshot zum Einstieg über Mein Stundenplan und iCal-Abo verwalten.

- Persönlicher WebUntis-iCal-Import per Datei oder manuellem HTTPS-Abruf aus der WebUntis-Domain; verschlüsselte Speicherung von Stundenplan und optionalen Abruf-Zugangsdaten.
- Explizite zentrale Zuordnung importierter Raum- und Klassen-/Gruppenbezeichnungen zu bestehenden Räumen und Klassen; keine automatische Anlage oder Änderung von Schüler:innen.
- Optionale Vorauswahl aktueller eindeutiger Termine, Tagesstundenplan und Vorschau kommender Stunden. Raum und verantwortliche Klasse können nach ausdrücklicher Bestätigung gemeinsam übernommen werden.
- Mehrdeutige Überschneidungen bleiben manuell; die nachfolgend ergänzte Gruppenverwaltung unterteilt eindeutige Klassenzuordnungen.

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
