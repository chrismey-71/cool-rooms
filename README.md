<p align="center">
  <img src="www/assets/cool-rooms-logo.svg" width="112" height="112" alt="COOL Rooms Logo">
</p>

<h1 align="center">COOL Rooms</h1>

<p align="center"><strong>Buche deinen Raum</strong></p>

<p align="center"><a href="https://github.com/chrismey-71/cool-rooms/releases/tag/v1.30">Version 1.30 · Release und Download</a></p>

<p align="center"><a href="https://chrismey-71.github.io/cool-rooms/">Projektwebseite mit Funktionsüberblick und Screenshots</a></p>

COOL Rooms ist eine webbasierte Raumorganisation für Schulen. Schüler:innen wählen ihren aktuellen Aufenthaltsraum, Lehrkräfte geben ihren eigenen Standort bekannt und behalten verantwortliche Klassen im Blick. Administrator:innen verwalten Räume, Klassen, Benutzerkonten, Kiosk-Zugänge und das Erscheinungsbild.

Die Anwendung ist für den Betrieb auf einem klassischen PHP-/MariaDB-Webspace ausgelegt und kann als Progressive Web App auf dem Home-Bildschirm eines Smartphones gespeichert werden.

## Funktionsumfang

### Schüler:innen

- Schnelle Raumwahl mit frei sortierbaren Gruppen wie Etage, Ort oder Funktion
- Kompakte Smartphone-Kacheln mit Raumsuche, Bereichsfilter und optional nur freien Räumen
- Anzeige des eigenen Klassenraums und des aktuell gewählten Raums
- Berücksichtigung von Raumkapazitäten und nicht verfügbaren Räumen
- Suche nach dem aktuellen Standort von Lehrkräften
- Reduzierte Oberfläche bei Anmeldung über den Kioskmodus

### Lehrkräfte

- Eigenen aktuellen Raum festlegen
- Verantwortliche Klasse auswählen und live anzeigen
- Schüler:innen als anwesend oder nicht anwesend kennzeichnen
- Alle Schüler:innen einer Klasse in deren Klassenraum buchen
- Raumzuordnungen der verantwortlichen Klasse zurücksetzen
- Klassen-Kiosk-PIN für einen zentralen Raum- oder Klassencomputer verwalten
- Passwörter beziehungsweise PINs der eigenen Klasse zurücksetzen

### Administration

- Räume, Kapazitäten, Sichtbarkeit und reine Lehrkräfteräume verwalten
- Raumgruppen, Farben und Reihenfolgen per Ziehen anordnen
- Klassen umbenennen, Klassenräume zuweisen und Klassen samt Schüler:innen löschen
- Schüler:innen einzeln oder per CSV verwalten
- Lehrkräfte und Administrator:innen verwalten
- Raumbelegung live überblicken
- Kioskmodus mit Gültigkeitszeitraum und Tagescode steuern
- Tagesdaten und Anwesenheitsstatus zurücksetzen
- Optionaler automatischer Tagesreset per Cron (nur Kommandozeile)
- Ereignisse und Kiosk-Nutzung auswerten
- Titel, Untertitel, Logo und Farbschema anpassen

### Technische Merkmale

- Rollenbasierte Zugriffe für Administration, Lehrkräfte und Schüler:innen
- Live-Ansichten über Server-Sent Events (SSE)
- Installierbare PWA mit Manifest und App-Icons
- Passwort-Hashing über die PHP Password API
- PDO mit echten Prepared Statements
- CSRF-Schutz für schreibende Aktionen
- Automatische Sitzungsbeendigung nach Inaktivität
- Responsives Layout für Desktop, Tablet und Smartphone

## Voraussetzungen

- PHP 8.0 oder neuer
- PDO-Erweiterung für MySQL/MariaDB
- PHP-Erweiterung `mbstring`
- PHP-Erweiterung Sodium oder OpenSSL für den verschlüsselten Kiosk-PIN-Speicher
- MySQL oder MariaDB mit InnoDB- und `utf8mb4`-Unterstützung
- Webserver mit PHP-Unterstützung, beispielsweise Apache oder nginx
- HTTPS für den produktiven Betrieb

Für Live-Ansichten darf der Webserver beziehungsweise ein vorgeschalteter Proxy SSE-Antworten nicht dauerhaft puffern.

## Neuinstallation

1. Eine leere MySQL- oder MariaDB-Datenbank mit eigenem Datenbankbenutzer anlegen.
2. Den Inhalt des Ordners `www/` in das Dokumentenverzeichnis der Domain oder in ein Unterverzeichnis hochladen.
3. Im Browser `https://example.org/install.php` beziehungsweise die entsprechende URL im Unterverzeichnis aufrufen. Die Seite erzeugt auf Wunsch lokal im Browser ein sicheres einmaliges Setup-Kennwort und zeigt den für diese Installation exakt erwarteten Dateinamen an, beispielsweise `install-secret-a1b2c3d4e5f6.php`.
4. `install-secret.sample.php` als Vorlage verwenden, mit dem angezeigten Namen direkt oberhalb des Dokumentenverzeichnisses ablegen und die auf der Seite beschriebenen Werte eintragen.
5. Sicherstellen, dass PHP im Ordner `www/` die Datei `config.php` sowie neben der externen Geheimnisdatei temporäre Dateien und den Sperrmarker mit der Endung `.complete` anlegen kann. Beide Verzeichnisse müssen auf demselben Dateisystem liegen.
6. `install.php` neu laden und das Setup-Kennwort aus der externen Installationsdatei eingeben.
7. Datenbankname, Datenbankzugang, App-Titel, Untertitel und das erste Administrationskonto eintragen.
8. Nach erfolgreicher Installation anmelden. `install.php` kann auf dem produktiven Webspace zusätzlich entfernt oder umbenannt werden.

### Externe Installationsdatei

`install-secret.sample.php` ist nur die mitgelieferte Vorlage. Die verwendete Kopie erhält auf dem Server den von `install.php` angezeigten Namen. Sie darf weder im Ordner `www/` noch in einem anderen über eine Webadresse erreichbaren Verzeichnis liegen.

Bei einer Installation unter `/users/cool-rooms` ist folgende Struktur vorgesehen:

```text
/users/cool-rooms/
├── install-secret-a1b2c3d4e5f6.php   Name aus install.php, nicht öffentlich
└── www/                        Dokumentenverzeichnis der Domain
    ├── install.php
    ├── index.php
    └── ...
```

Der Inhalt der angezeigten Installationsdatei lautet beispielsweise:

```php
<?php
declare(strict_types=1);

return [
  // Erzeugen mit: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  'token' => 'HIER_DAS_ERZEUGTE_64_STELLIGE_ZUFALLSTOKEN',

  // Häufig "localhost"; die korrekte Angabe nennt der Hostinganbieter.
  'db_host' => 'localhost',

  // null verwendet den Standardport von MySQL/MariaDB.
  'db_port' => null,
];
```

### Optional: eigener Dateiname

Für den normalen Installationsweg ist keine Umgebungsvariable erforderlich. Wer stattdessen einen eigenen festen Dateinamen wie `install-secret.php` verwenden möchte, kann dessen absoluten Pfad optional über `COOL_ROOMS_INSTALL_CONFIG` vorgeben. Diese Variable ist keine weitere Datei, sondern eine Einstellung des Webservers beziehungsweise der PHP-Laufzeit:

```text
Name:  COOL_ROOMS_INSTALL_CONFIG
Wert:  /users/cool-rooms/install-secret.php
```

Je nach Hosting wird diese Einstellung an einer anderen Stelle vorgenommen:

- Im Hosting-Verwaltungsbereich wird sie unter Bezeichnungen wie **Umgebungsvariablen**, **Environment Variables** oder **PHP-Einstellungen** angelegt.
- Bei Apache kann, sofern der Hostinganbieter `SetEnv` erlaubt, folgende Zeile in der Datei `www/.htaccess` stehen:

```apache
SetEnv COOL_ROOMS_INSTALL_CONFIG /users/cool-rooms/install-secret.php
```

- Bei nginx kann der Serveradministrator den Pfad im PHP-Abschnitt hinterlegen:

```nginx
fastcgi_param COOL_ROOMS_INSTALL_CONFIG /users/cool-rooms/install-secret.php;
```

Ohne diese optionale Einstellung berechnet der Installer automatisch einen installationsspezifischen Dateinamen. `install.php` zeigt diesen Namen und den vorgesehenen Ablageort direkt an. Der absolute interne Serverpfad wird aus Sicherheitsgründen nicht öffentlich ausgegeben.

Pfade innerhalb des Dokumentenverzeichnisses oder des öffentlich ausgelieferten COOL-Rooms-Ordners werden auch bei Alias- und Unterverzeichnis-Installationen abgewiesen.

Der Datenbankserver wird ausschließlich aus dieser externen Datei übernommen und kann nicht über das Webformular geändert werden. Nach erfolgreicher Installation entfernt der Installer die Geheimnisdatei und behält den installationsspezifischen Sperrmarker `<Geheimnisdatei>.complete`. Das bloße Löschen von `www/config.php` öffnet den Installer daher nicht erneut. Ein technisch abgebrochener Lauf kann sicher wiederholt werden; bereits vollständig angelegte Daten werden anhand eines Installationsmerkmals erkannt. Für eine bewusste Neuinstallation müssen Datenbank, `www/config.php`, Sperrmarker und ein neu erzeugtes Setup-Kennwort kontrolliert vorbereitet werden.

Der Installer erkennt das verwendete Unterverzeichnis automatisch. Für eine Neuinstallation muss eine leere, ausschließlich für COOL Rooms vorgesehene Datenbank verwendet werden. Technische Installationsfehler erscheinen nicht im Browser, sondern im PHP-Serverprotokoll mit einer Referenznummer.

## Bestehende Installation aktualisieren

Vor jedem Update eine Sicherung der Datenbank und der bestehenden Dateien erstellen.

Beim Überspielen einer neuen Version müssen folgende lokale Dateien erhalten bleiben:

- `www/config.php`
- der Sperrmarker `<Geheimnisdatei>.complete` außerhalb des Dokumentenverzeichnisses
- eigene Logos unter `www/assets/branding/`
- gegebenenfalls benötigte lokale Logdateien

Danach werden die Anwendungsdateien aus `www/` aktualisiert. Der Installer darf für ein normales Update nicht erneut ausgeführt werden. Einige ergänzende Tabellen oder Spalten legt die Anwendung beim ersten Aufruf der zugehörigen Verwaltungsseite selbstständig an; eine Datensicherung bleibt dennoch erforderlich.

Bei einer Git-basierten Aktualisierung dürfen ignorierte Betriebsdateien nicht mit Befehlen wie `git clean -fdx` gelöscht werden.

### Kiosk-PIN-Migration bei einem Update

Mit Migration ist die automatische Umstellung der gespeicherten Kiosk-Zugangscodes gemeint. In älteren Versionen lagen der schulweite Code und anzeigbare Klassen-PINs als lesbarer Text in der Datenbank. Die Anwendung übernimmt diese vorhandenen Werte in den verschlüsselten Speicher. Der Code beziehungsweise die PIN wird dabei nicht neu vergeben und bleibt innerhalb des eingestellten Kiosk-Zeitraums nutzbar.

Der schulweite Code erhält zusätzlich einen Passwort-Hash für die Anmeldung. Bei Klassen-PINs bleibt der vorhandene Hash erhalten; die alte Klartextspalte `code_plain` wird nach dem Übertragen der Werte entfernt. Die Anzeige für berechtigte Lehrkräfte und Admins bleibt über den verschlüsselten Speicher möglich. Alte Klassen-PINs, für die ausschließlich ein Hash und kein anzeigbarer Wert gespeichert wurde, bleiben zur Anmeldung nutzbar, können aber nicht nachträglich aus dem Hash angezeigt werden.

Die Umstellung erfolgt beim ersten Aufruf eines entsprechenden Kiosk-Bereichs. Vorher Datenbank und `www/config.php` sichern. Für die automatische Schemaänderung muss der Datenbankbenutzer die betreffende Tabelle ändern dürfen (`ALTER`). Nach dem Update vorhandene Schul- und Klassen-PINs zur Anmeldung und in der Anzeige prüfen. Der Installer wird dafür nicht erneut ausgeführt.

Bei Neuinstallationen erzeugt der Installer einen `credential_key` in `www/config.php`. Dieser Schlüssel muss zusammen mit der Datenbank gesichert und bei Updates beibehalten werden. Bei älteren Installationen ohne diesen Eintrag wird der Schlüssel aus den bestehenden Datenbankzugangsdaten abgeleitet. Werden diese Zugangsdaten später geändert oder ein neuer Schlüssel nachgetragen, ist eine gesonderte Umstellung des verschlüsselten Speichers notwendig; sonst können vorhandene PINs nicht mehr angezeigt werden. Alte Sicherungen können weiterhin Klartext-PINs enthalten und müssen entsprechend geschützt werden.

## Konfiguration und Erscheinungsbild

Der Installer erzeugt `www/config.php` aus den eingegebenen Daten. Diese Datei enthält Datenbankzugangsdaten, ist durch `.gitignore` von Veröffentlichungen ausgeschlossen und darf nicht in ein öffentliches Repository gelangen.

Titel, Untertitel, Logo und Farben können nach der Installation unter **Administration → Erscheinungsbild** geändert werden. Fehlt ein gespeichertes Logo auf dem Server, verwendet die App automatisch das neutrale Standardlogo.

Das Standardlogo und alle App-Icons verwenden dasselbe CR-Raumzeichen mit Standortpunkt. Die SVG-Vorlage liegt unter `www/assets/cool-rooms-logo.svg`, die identische Fassung der Projektseite unter `docs/assets/cool-rooms-logo.svg`. Auf macOS erzeugt `swift tools/render_icons.swift` aus der Vorlage alle sechs PNG-Dateien unter `www/assets/icons/`. iPhone- und maskierbares Icon haben einen vollflaechigen, nicht transparenten Hintergrund; die wichtige Bildmarke bleibt innerhalb der [maskierbaren Sicherheitszone](https://www.w3.org/TR/appmanifest/#icon-masks). Das Buildskript wird auf dem Webserver nicht benoetigt. Bei kuenftigen Icon-Aenderungen `APP_ICON_REVISION` in `www/lib/version.php` sowie die Cache-Kennung in `docs/index.html` anpassen; die App-Versionsnummer ist davon unabhaengig. Eigene hochgeladene Schullogos bleiben erhalten.

Die Datei `www/config.sample.php` zeigt die unterstützten Grundeinstellungen. `base_url` bleibt leer, wenn COOL Rooms direkt unter einer Domain betrieben wird, oder enthält beispielsweise `/cool-rooms`, wenn die App in einem Unterverzeichnis liegt.

## Kioskmodus

Der Kioskmodus ist für gemeinsam genutzte Schulcomputer gedacht. Ein Administrationscode kann Zugriff auf alle Klassen ermöglichen; Lehrkräfte können zusätzlich einen PIN für ihre verantwortliche Klasse bereitstellen. Schüler:innen wählen am zentralen Computer ihren Namen und anschließend ihren Raum.

Eine ausschließlich über den Kiosk erfolgte Anmeldung bietet nur Raumwahl und Lehrkräftesuche. Nach Inaktivität kehrt die Anwendung automatisch zum Kiosk-Einstieg zurück.

Unter **Administration → Login-Schutz** können die erlaubten Fehlversuche und die Sperrdauer festgelegt werden. Zulässig sind 3 bis 20 Fehlversuche und 1 bis 30 Minuten Sperrdauer. Die Einstellung ändert keinen gespeicherten PIN und macht keinen COOL-PIN dauerhaft ungültig.

### Login-Schutz im gemeinsamen Schulnetz ab Version 1.23

- Normale Logins werden pro Benutzerkonto begrenzt, nicht über einen gemeinsamen niedrigen IP-Fehlversuchszähler. Ein gesperrtes Konto bleibt auch bei einem IP-Wechsel gesperrt; andere Konten derselben Schul-IP bleiben nutzbar. Auch unbekannte Benutzernamen erhalten dieselbe Begrenzung und neutrale Fehlermeldungen. Die Schreibweise vorhandener Konten wird vor der Begrenzung mit der Datenbank abgeglichen.
- Kiosk-Codeprüfungen werden pro Browser begrenzt. Eine signierte zufällige Browserkennung im HttpOnly-/SameSite-Sitzungscookie bleibt bei Logout und Session-ID-Wechsel erhalten. Sie verleiht keine Kiosk-Berechtigung. Bereits freigeschaltete Schul- und Klassen-Kioske bleiben gültig, solange ihre bisherigen Zugangsbedingungen erfüllt sind.
- Nach Ablauf der Sperrdauer steht wieder die volle Versuchszahl bereit. Erfolgreiche Anmeldungen löschen nur den zugehörigen Konto- oder Browserzähler. Parallele Versuche werden vor der Passwort-/PIN-Prüfung atomar reserviert; die letzte erlaubte Eingabe kann noch erfolgreich sein.
- Alte gemeinsame Sperren (`login_source`, `kiosk_source`, `kiosk_global`) werden beim ersten Zugriff auf die Schutzfunktionen einmalig entfernt. Bestehende Kontosperren, PINs und Stammdaten bleiben erhalten. Es ist keine Neuinstallation erforderlich.

Zusätzlich gibt es getrennte großzügige **Kurzzeitlimits je IP**: standardmäßig 600 normale Login-Anfragen und 120 Kiosk-Codeprüfungen in einem 60-Sekunden-Fenster ab der ersten Anfrage. Auch erfolgreiche und bereits konto-/browserseitig gesperrte Prüfversuche zählen dabei mit; GET-Seitenaufrufe und die Namenswahl in einem bereits freigeschalteten Kiosk zählen nicht. Ein Erfolg setzt dieses IP-Budget nicht zurück. Nach Ausschöpfen werden weitere Prüfungen nur bis zum Ende des laufenden Fensters abgewiesen (höchstens 60 Sekunden), ohne es durch weitere Anfragen zu verlängern. Andere Geräte derselben IP können in diesem Ausnahmefall kurz betroffen sein. Die Adminseite erlaubt 100–5000 Login-Anfragen beziehungsweise 30–1000 Kiosk-Prüfungen je Minute; große Schulen sollten diese Werte anhand der tatsächlichen Last anpassen.

Eine Browserkennung ist kein vollständiger Schutz gegen automatisiertes PIN-Raten: Cookie-Löschung oder ein neuer Browser können eine neue Kennung erzeugen; verteilte Angriffe über mehrere IP-Adressen können auch den IP-Schutz umgehen. Kontosperren können zudem durch absichtliche Fehlversuche gegen ein bekanntes Konto ausgelöst werden. Kurze PINs daher nur für beaufsichtigte Kioske und begrenzte Gültigkeitszeiträume einsetzen. Die Server-IP stammt ausschließlich aus `REMOTE_ADDR`; hinter Reverse Proxies muss der Betreiber diese Adresse vertrauenswürdig aufbereiten. Ungeprüfte `X-Forwarded-For`-Header werden nicht verwendet.

### Regressionstests

`tests/security_integration.php` prüft die Begrenzungen mit echten PDO-/MariaDB-Transaktionen, mehreren Browserkennungen und 20 parallelen Prozessen. Nur gegen eine **separate, leere Testdatenbank** namens `cool_rooms_security_test` ausführen, niemals gegen eine produktive Datenbank. Eine separate Kopie des Projektordners vorbereiten, dort `www/config.php` mit ausschließlich lokalen Testzugangsdaten anlegen und den absoluten Pfad dieser Kopie über `COOL_ROOMS_TEST_ROOT` übergeben:

```sh
COOL_ROOMS_TEST_ROOT=/ABSOLUTER/PFAD/ZUR/TESTKOPIE php tests/security_integration.php
```

Die Testdatenbank muss vor jeder Ausführung leer sein; PHP-CLI benötigt PDO-MySQL sowie `proc_open` für die Parallelitätstests. Die Arbeitskopie selbst wird als Ziel abgewiesen. Die Tests erstellen ausschließlich Beispieldaten in der ausdrücklich gewählten Testinstallation.

## Persönlicher Stundenplan und WebUntis Import

Lehrkräfte erreichen unter **Stundenplan** beziehungsweise im Lehrer:innen-Dashboard ihren persönlichen Import. Dieser Bereich ist unabhängig von COOL-Grades und verwendet ausschließlich den persönlichen Kalender der angemeldeten Lehrkraft.

Vor dem ersten Import zeigt das Dashboard nur die schmale Zeile **Mein Stundenplan · Noch kein Import** mit dem Einstieg **Importieren**. Auf Mobilgeräten öffnet die Navigation als separates Menüfenster unter dem Kopfbereich, ohne dessen Höhe oder die Position des Seiteninhalts zu verändern. Erneuter Klick auf die Menütaste, Außenklick, Escape oder Verlassen des Menüs per Tastatur schließen das Fenster. Die Desktop-Navigation und rollenabhängigen Zugänge bleiben unverändert.

1. Den persönlichen WebUntis-Kalender als `.ics`-Datei hochladen (bis 2 MB, höchstens 2000 Einzeltermine). Alternativ eine persönliche HTTPS-iCal-Adresse unter `*.webuntis.com` eintragen und manuell abrufen. Für den Abruf wird PHP-cURL benötigt; der Dateiimport benötigt keine Verbindung zu WebUntis.
2. Falls der iCal-Endpunkt HTTP-Basic-Authentifizierung verlangt, zusätzlich Benutzername und Kennwort eintragen. Ein geheimer Schlüssel im iCal-Link reicht häufig aus. Die Anwendung meldet sich nicht über das normale WebUntis-Loginformular an.
3. Die Administration pflegt in der eigenen Kachel **Administration → Lehrkräfte & Überblick → Zuordnung → WebUntis-Kürzel zuordnen** einmalig Raum-, Klassen-/Teilgruppen- und Lehrkraftkürzel. Die Kachel verlinkt direkt auf **Räume zuordnen**, **Klassen zuordnen** und **Lehrer:innen zuordnen**. Beispielsweise `H → Raum H`, `2Aa → 2A` und `MEYS → die entsprechende Lehrkraft`. Bereits importierte Kalender liefern die Kürzel für die Liste; weitere Kürzel können vorab ergänzt werden. Neue Räume, Klassen oder Konten werden dabei nicht angelegt.
4. Die **Stundenplan-Vorauswahl** ist bei neuen persönlichen Einstellungen standardmäßig aktiviert und kann ausgeschaltet werden. Eine bereits gespeicherte Deaktivierung bleibt erhalten. Eindeutige aktuelle Termine werden in **Mein Raum** und **Verantwortliche Klasse** vorausgewählt. Bei gleichzeitig unterschiedlichen Räumen oder Klassen erfolgt keine entsprechende Vorauswahl.
5. Im **Dashboard** erscheinen höchstens drei Termine eines Tages als kompakte Kacheln mit Uhrzeit, Fach, Raum und Klasse. Standardmäßig beginnt die Ansicht heute mit der laufenden oder nächsten noch nicht beendeten Stunde. Sind alle Stunden vorbei, werden die letzten drei gezeigt. Ohne Stunden bleibt der Tag leer; es wird nicht unbemerkt zum nächsten Tag gesprungen. Die Pfeile neben dem Datum wechseln den Tag, die Pfeile neben **1–3 von …** verschieben die Anzeige um eine Stunde. **Heute** springt wieder zur aktuellen Tagesauswahl. Kleine Tasten **Raum**, **Klasse** und **Beides** unter **Zuordnen:** übernehmen die jeweilige Auswahl; **Alle Termine & Einstellungen** öffnet den vollständigen Stundenplan. Auf schmalen Bildschirmen sind die drei Kacheln zusätzlich seitlich scrollbar. Einzelübernahmen ändern die jeweils andere Auswahl nicht. Die Übernahme betrifft nur Standort und verantwortliche Klasse der Lehrkraft, niemals die Raumbuchungen von Schüler:innen.

Die zentrale Zuordnung wirkt sofort für alle Lehrkräfte, ohne erneuten Import. Sobald sie erstmals gespeichert wurde, ist sie maßgeblich; alte persönliche Raum-/Klassenmaps werden nicht mehr als Fallback verwendet. Bis zur ersten zentralen Einrichtung bleiben bestehende persönliche Maps übergangsweise wirksam. Ein leeres zentrales Ziel verhindert die entsprechende Vorauswahl. Auch **inaktive Räume** werden mit Kennzeichnung angeboten und können vorab zugeordnet bleiben. Sie werden dadurch nicht aktiviert und können erst nach Aktivierung als Standort übernommen werden. Gelöschte/inaktive Zielräume oder nicht mehr vorhandene Klassen werden vor jeder Übernahme erneut geprüft.

Ein persönlicher Stundenplan ist ausschließlich für Konten mit der Rolle `teacher` vorgesehen. Reine Administrationskonten sehen keinen Stundenplan-Menüpunkt und können keine persönlichen Importe oder Stundenplanübernahmen aufrufen. Die zentrale Verwaltung aller Kürzel bleibt Admins vorbehalten. Lehrkraftkonten mit zusätzlichen Adminrechten behalten ihren persönlichen Stundenplan; bei der Lehrkraftzuordnung werden nur tatsächliche Lehrkraftkonten angeboten. Vierstellige Kürzel wie `MEYS` werden ausdrücklich im Bereich **Lehrer:innen zuordnen** gepflegt, ohne andere Kürzellängen auszuschließen.

WebUntis-Lehrkraftkürzel werden aus den Beschreibungen des Exports gelesen und zentral Konten zugeordnet. Listen innerhalb von Klassen und Lehrkräften sind durch Semikola getrennt; ein Leerraum trennt die beiden Listen. Beispielsweise enthält `3FSBa; 3FSBb MEYS` zwei Teilgruppen und eine Lehrkraft. `MEYS` ohne Klassenblock ist ein reiner Lehrkrafttermin, keine Klasse. In Sammelterminen werden auch die weiteren Kürzel der Lehrkraftliste berücksichtigt. Die Zuordnungsmaske zeigt links **WebUntis-Lehrkraftkürzel** und rechts **Lehrer:in in COOL Rooms (Name)**. Der Login-Benutzername wird bei vorhandenen Personennamen nicht zusätzlich als vermeintliches Zielkürzel angezeigt.

Termine, deren bekannte Kürzel ausschließlich auf andere Lehrkräfte zeigen, werden nicht übernommen. Das ist eine Plausibilitätsprüfung, kein Kontologin und keine automatische Verteilung eines fremden Kalenders. Persönliche iCal-Adressen und Zugangsdaten bleiben im jeweiligen Konto. Bei älteren Importen korrigiert die App die eindeutigen Fehlklassifizierungen des früheren Parsers für die Anzeige: bereits als Lehrkraft bekannte Kürzel sowie vierstellige Großbuchstaben-Kürzel aus erkennbaren Sammelterminen mit nummerierten Klassen. Tatsächlich vorhandene gleichnamige Klassen werden dabei geschützt. Die verschlüsselten Originaldaten werden beim Lesen nicht überschrieben. Zweifelhafte Altformate können durch einen erneuten Import vollständig ausgewertet werden.

Erkannte Lehrkraftkürzel aus alten gespeicherten Klassen-Zuordnungen erscheinen in der zentralen Maske nicht mehr unter Klassen, sondern unter Lehrer:innen. Vorhandene korrekte Lehrkraftziele bleiben erhalten. Ein früheres Klassen-Ziel wird niemals automatisch in eine Lehrkraftkennung umgedeutet. Mit **Alle Zuordnungen speichern** bestätigt der Admin die bereinigte Zuordnung; bis dahin bleibt die gespeicherte Konfiguration unverändert. Unbekannte Kürzel bleiben der Administration zur Zuordnung überlassen.

`teacher/timetable.php` zeigt vor dem ersten Import die geöffnete Anleitung oben und darunter die geöffneten **Import und persönlichen Einstellungen**. Nach einem Import steht wie gewohnt die Tagesübersicht mit Vor-/Zurück-Navigation zuerst; Einstellungen und Hilfe sind separat aufklappbar. Auch ein gültiger leerer Kalender zählt als abgeschlossener Import. Es gibt dort keine langen persönlichen Kürzel-Zuordnungslisten mehr. Die Anleitung mit dem bereitgestellten Screenshot beschreibt **Mein Stundenplan → Drei-Punkte-Menü → iCal-Abo verwalten**. Weitere WebUntis-Dialoge können je nach Version und Freigabe abweichen.

Die Vorauswahl ist ausdrücklich **keine unbeaufsichtigte automatische Buchung**: GET-Seitenaufrufe, Zeitablauf und Import ändern keine aktuellen Raum-/Klassenbuchungen. Die Lehrkraft bestätigt Änderungen per CSRF-geschütztem POST und kann die Vorschläge überschreiben. Ohne aktivierte Vorauswahl bleiben die bisherigen Auswahlfunktionen unverändert. Klassengruppen können zusätzlich die Ansicht **Klasse live anzeigen** sowie deren Sammelaktionen eingrenzen, wie nachfolgend beschrieben.

Tagesstundenplan und nächste Termine werden in `Europe/Vienna` dargestellt. UTC und explizite IANA-Zeitzonen aus iCal werden berücksichtigt; Termine ohne Zeitzonenangabe werden als österreichische Ortszeit interpretiert. Wiederholungsregeln und Wiederholungsausnahmen innerhalb von Unterrichtsterminen werden ausdrücklich abgewiesen: einen WebUntis-Export mit einzelnen Terminen verwenden. Ganztägige Ereignisse werden nicht als Unterrichtsstunden übernommen. Fehlgeschlagene Importe ersetzen den bisherigen Kalender nicht. Ein erneuter Import ersetzt den persönlichen Kalender, ohne Räume, Klassen oder Benutzerkonten anzulegen.

Die neue Tabelle `teacher_timetables` wird bei Erstnutzung angelegt. Persönliche Importdaten und optionale URL-/HTTP-Zugangsdaten werden mit dem vorhandenen Konfigurationsschlüssel verschlüsselt gespeichert und nur für die angemeldete Lehrkraft geladen. `www/config.php` beziehungsweise dessen `credential_key` bei Updates erhalten und gemeinsam mit der Datenbank sichern. Rohdateien werden nicht im Webverzeichnis abgelegt. Gespeicherte Zugangsdaten erscheinen nicht erneut im Formular; leere Felder behalten sie. Bei Wechsel der Abrufadresse werden alte HTTP-Zugangsdaten nicht automatisch an die neue Adresse weitergereicht: falls nötig erneut eingeben. **Import und Zugangsdaten löschen** entfernt die persönlichen Importdaten, nicht den aktuellen Standort oder die verantwortliche Klasse.

Der URL-Abruf erfolgt nach Klick oder durch den optional eingerichteten Cron-Job bei aktivierter **Gespeicherte WebUntis-Adresse automatisch aktualisieren**. Diese separate Einstellung ist standardmäßig aktiv; eine gespeicherte Deaktivierung bleibt erhalten. Ohne gespeicherte Adresse wird nichts abgerufen. Ein Datei-Upload schaltet den automatischen Abruf aus, damit ein älterer gespeicherter Link die Datei nicht am nächsten Morgen überschreibt. Zum Wechsel zurück die Adresse manuell importieren und die automatische Aktualisierung wieder aktivieren. Der Abruf erlaubt ausschließlich HTTPS auf Port 443 zu Subdomains von `webuntis.com`, mit TLS-Prüfung, fixierter öffentlicher Ziel-IP, Größen-/Zeitlimits und ohne Redirects. Andere Anbieter oder abweichende WebUntis-Domains zunächst per Datei importieren. Die App protokolliert keine Abruf-URLs oder Kennwörter; Betreiber müssen auch Proxy-/Webserver- und Datenbankprotokolle entsprechend schützen.

Die Altformat-Erkennung berücksichtigt auch den allerersten Import ohne `teachers`-Feld und zieht vorhandene zentrale Lehrkraftkürzel hinzu. Bereits damals vollständig verworfene Kürzel lassen sich allerdings nur durch einen neuen Dateiimport wiederherstellen. Die Adminseite zeigt zur Kontrolle die Kennung **Import-Erkennung: 2026-11-25-r3**. Fehlt diese, läuft dort noch nicht diese Korrektur.

Tests für Parser, Zeitzonen, Überschneidungen und Zielzuordnung: `php tests/timetable_test.php` (PDO-SQLite für die isolierten Zuordnungstests erforderlich).

## Gruppen innerhalb einer Klasse

### Einrichten durch die Administration

1. **Administration → Klassen → Gruppen & Schüler:innen zuordnen** bei der gewünschten Klasse öffnen.
2. Freie Gruppennamen anlegen, beispielsweise **a** und **b** in der Klasse **1HLS**. Andere Namen und mehr als zwei Gruppen sind möglich.
3. Pro Gruppe optional das exakte WebUntis-Kürzel eintragen, etwa **1HLSa** beziehungsweise **1HLSb**. Ein Kürzel darf nur einer Gruppe zugeordnet sein. Diese Regeln gelten zentral für alle Lehrkräfte und haben Vorrang vor allgemeinen Klassenzuordnungen. Die Endbuchstaben werden nicht automatisch geraten.
4. In derselben Ansicht bei jedem Schülernamen die Gruppe wählen und **Gruppenzuordnungen speichern** klicken. Jede Person kann höchstens einer Gruppe ihrer eigenen Klasse angehören; **Ohne Gruppe** bleibt möglich. Alternativ lässt sich die Gruppe im Bearbeitungsformular der Schüler:innenverwaltung setzen. Dort wird die bestehende Gruppe auch in der Liste angezeigt.

Die zentrale WebUntis-Verwaltung zeigt die Gruppenkürzel mit einem Link zur zuständigen Gruppenverwaltung an. Allgemeine Klassenkürzel, Räume und Lehrkräfte werden weiterhin wie gewohnt zugeordnet. Ein Gruppenname kann geändert werden, ohne die Mitgliedschaften neu vergeben zu müssen. Beim Umbenennen der ganzen Klasse bleiben Gruppen, Mitgliedschaften und zentrale Klassenziele erhalten; geänderte WebUntis-Kürzel für ein neues Schuljahr bitte ausdrücklich nachführen. Ein einzelner Klassenwechsel in der Schüler:innenverwaltung oder im CSV-Import übernimmt keine alte Gruppe der vorherigen Klasse.

### Anzeige der Lehrkräfte

- Bei eingeschaltetem Stundenplan und einer passenden laufenden Stunde zeigt **Klasse live anzeigen** automatisch die zugehörige Gruppe der verantwortlichen Klasse. Wurde über das Dashboard ausdrücklich die Klasse eines kommenden Termins übernommen, gilt dessen Gruppenauswahl bis zum Terminende. Eine reine Raumübernahme ändert die Gruppenauswahl nicht.
- Enthält ein Termin nur **1HLSa**, erscheint ausschließlich Gruppe **a**. Enthält er **1HLSa und 1HLSb** und sind dies alle angelegten Gruppen, erscheint die **ganze Klasse**, einschließlich Schüler:innen ohne Gruppenzuordnung. Bei drei Gruppen bleiben zwei genannte Gruppen eine Teilmenge. Eine einzige angelegte Gruppe wird nicht automatisch mit der ganzen Klasse gleichgesetzt. Ein ausdrücklich auf die Gesamtklasse abgebildetes Klassenkürzel steht weiterhin für die ganze Klasse.
- Der aktive Filter und die Zahl sichtbarer Personen stehen deutlich über der Liste. Aktionen zum Buchen in den Klassenraum, Löschen von Raumzuordnungen sowie Anwesenheitsänderungen betreffen nur diese sichtbare Auswahl. Ein veraltetes Formular nach einem Stunden-/Filterwechsel wird nicht ausgeführt, sondern muss erneut geprüft werden.
- Ohne genutzten Stundenplan startet die Ansicht mit der ganzen Klasse. Die Gruppenwahl ist oberhalb der Liste **immer sichtbar**, auch bei aktivem Stundenplanfilter: **Ganze Klasse** zeigt den ganzen Verband, die Gruppentasten (z. B. **a**, **b**) die jeweilige Teilgruppe. **Stundenplan** kehrt zur automatischen Auswahl zurück und wird bei einem genutzten importierten Kalender angeboten. Eine bewusst manuell gewählte Ansicht bleibt für diesen Seitenaufruf einschließlich Formularaktionen und Live-Verbindung erhalten; sie ändert weder den Kalender noch die verantwortliche Klasse. Fremde Klassengruppen werden weiterhin abgewiesen. Die verantwortliche Klasse muss wie bisher zuerst gewählt werden.
- Die Live-Verbindung liefert bei einer Gruppenansicht nur die zugehörigen Raumänderungen. An einer Stundenplangrenze wird die Ansicht einmal aktualisiert, nicht im schnellen Dauertakt. Gruppenzuordnungen der Administration lösen eine Aktualisierung der betroffenen Klassenansicht aus.

Gruppen sind eine Unterteilung der bestehenden Klasse, keine neuen Klassen und keine eigenen Buchungskonten. Klassenräume und Klassen-Kiosk-PINs bleiben klassenbezogen. Das Löschen einer Gruppe entfernt nur die Gruppe und ihre Mitgliedschaften; Schüler:innen, Anwesenheit und Buchungen bleiben erhalten. Das Löschen einer ganzen Klasse entfernt nach der bestehenden Warnung auch deren Schüler:innen und Gruppen. Der nächtliche Tagesreset behält die Gruppen und Mitgliedschaften bei, entfernt aber wie bisher die verantwortlichen Klassen und damit auch ausdrücklich übernommene Stundenplantermine.

Die zusätzlichen Tabellen `class_groups`, `student_class_groups` und `teacher_class_calendar_selection` werden bei Installation beziehungsweise Erstnutzung automatisch angelegt. Vor dem Update Datenbank und Dateien sichern, die vollständigen aktualisierten `www/`-Dateien übernehmen und die eigene `config.php` samt Verschlüsselungsschlüssel erhalten. Auf dem Server kann `www/` weiterhin `public_html/` heißen. Ein erneuter Installer-Aufruf ist nicht nötig; der Datenbankbenutzer benötigt wie bei den bisherigen automatischen Tabellenanlagen `CREATE`-Rechte. Kalender und zentrale Zuordnungen bleiben erhalten.

Tests: `php tests/class_groups_test.php` (PDO-SQLite) und `COOL_ROOMS_TEST_ROOT=/tmp/app-kopie php tests/class_groups_integration.php` (ausschließlich separate App-Kopie mit anfangs leerer MariaDB-/MySQL-Datenbank `cool_rooms_groups_test`). Der Integrationstest rollt seine Testdaten zurück.

### Kompakte Klassenübersicht

`teacher/view_class.php` zeigt Nummer, Name, Raum und Anwesenheit. Username und Gruppenspalte entfallen; die aktive Klasse/Gruppe steht einmal im Kopf. Auf dem Smartphone werden Name und Raum in einer schmalen zweizeiligen Zeile zusammengefasst. Lange Namen und Raumbezeichnungen dürfen umbrechen, statt horizontalen Bildlauf zu erzwingen. Die Zeilennummer ist wie bisher nur eine laufende Anzeige, keine feste Schüler:innenkennung.

Oben stehen die Anzahl aller Personen der Klasse/Gruppenauswahl, die anwesenden Personen und die **anwesenden Personen ohne Raum**. Die Namenssuche findet auch Schreibweisen ohne Umlaut und beliebige Reihenfolgen von Vor-/Nachname. Sie blendet nur Zeilen aus und ändert weder Buchungen noch den zugrunde liegenden Gruppenfilter. Die laufende Nummerierung passt sich an die Suchtreffer an. Mit leerem Suchfeld erscheint wieder die ganze Liste, ohne seitenweises Blättern.

Die farbige Statustaste zeigt **Anwesend** oder **Nicht anwesend** und schaltet per Klick den Status um; der nächste Schritt steht im Tooltip und in der Beschriftung für Screenreader. Ein zusätzliches Symbol unterscheidet beide Zustände ohne reine Farberkennung. Nach dem Absenden wird die bisherige Suche/Scrollposition einmalig für dieselbe Lehrkraft und denselben Klassen-/Stundenplanfilter wiederhergestellt, sofern der Browser Sitzungsspeicher erlaubt. Diese kurzlebige Merkhilfe wird beim nächsten Laden entfernt und höchstens 60 Sekunden verwendet.

Die seltener benötigten Sammelaktionen liegen hinter **Aktionen**. Sie betreffen weiterhin die gesamte aktive Klasse oder Stundenplangruppe, **nicht nur die Treffer der Namenssuche**; Panel und Bestätigungsfrage weisen ausdrücklich darauf hin. Raumänderungen und die Zähler werden live aktualisiert. Der bisherige Raumstand bleibt als Tooltip am Raum verfügbar, ohne eine eigene Zeitspalte zu beanspruchen. Ohne JavaScript bleiben die serverseitige Liste und Formulare nutzbar; Suche und Live-Aktualisierung entfallen dann.

Beim Update auch die neuen Dateien `www/partials/class_roster.php` und `www/assets/class-roster.js` mitübertragen. Tests: `php tests/class_roster_render_test.php` und `node tests/class_roster_test.cjs` (ohne weitere Pakete).

### Raumwahl auf dem Smartphone

`student/home.php` zeigt auf schmalen Displays zwei Raumkacheln pro Zeile, auf großen Bildschirmen bis zu vier. Die vom Admin festgelegte Bereichsreihenfolge und Farben bleiben erhalten. Raumnamen dürfen vollständig umbrechen. Freie Plätze, **Voll**, **Klassenraum** und **Dein Raum** sind direkt an der Kachel erkennbar. Die schmale Statuszeile oben nennt den aktuellen Raum.

Die Suche findet Räume sowie Etagen, Orte und Funktionen, auch ohne Umlaute. Der Bereichsfilter und **Nur freie** lassen sich damit kombinieren. Leere Bereiche verschwinden aus den Treffern; **Filter zurücksetzen** zeigt wieder alle Räume. Die Filter ändern keine Buchungen und senden keine Suchbegriffe an den Server. Nach einer Buchung erscheint wieder die ungefilterte Auswahl mit dem neu gewählten Raum.

Ein Antippen bucht unmittelbar wie bisher. Volle Räume sind gesperrt; der eigene aktuelle Raum bleibt auswählbar. Die Kapazitätsprüfung erfolgt unverändert auf dem Server. **Unterrichtsende / Abmelden** entfernt die Raumbuchung. Das bisherige Auswahlmenü bleibt als eingeklappte Alternative erhalten. Ohne JavaScript sind alle Kacheln und Buchungsformulare nutzbar; nur die Filter entfallen. Kiosk-Anmeldung und automatische Kiosk-Rückkehr bleiben unverändert.

Beim Update auch `www/partials/student_rooms.php` und `www/assets/student-rooms.js` mitübertragen. Tests: `php tests/student_rooms_render_test.php` und `node tests/student_rooms_test.cjs` (ohne zusätzliche Pakete).

## WebUntis täglich um 07:00 Uhr aktualisieren

`cron/sync_timetables.php` aktualisiert gespeicherte Kalender aller tatsächlichen Lehrkraftkonten mit iCal-Adresse und eingeschalteter automatischer Aktualisierung. Die Einstellung für die **Vorauswahl** bleibt davon unabhängig: Eine deaktivierte Vorauswahl wird durch den Cron-Job nicht eingeschaltet. Reine Admin-Konten und Dateiimporte ohne Adresse werden nicht abgerufen. Das Skript ändert ausschließlich den persönlichen Kalender und dessen Importzeitpunkt, **keine Räume, verantwortlichen Klassen, Anwesenheiten, Schüler:innen-Buchungen, PINs oder zentralen Kürzelzuordnungen**.

### Dateien und Voraussetzungen

`cron/` außerhalb des öffentlich erreichbaren Verzeichnisses ablegen. Bei deinem Hosting mit `public_html` beispielsweise:

```text
/users/cool-rooms/
├── cron/
│   └── sync_timetables.php
├── logs/                           selbst anlegen, nicht öffentlich
└── public_html/
    ├── config.php                  unverändert erhalten
    └── lib/
        ├── timetable.php
        ├── timetable_sync.php
        └── secrets.php
```

Ohne Pfadangabe erkennt das Skript genau ein benachbartes `public_html/` oder `www/` mit `config.php`. Sind beide vorhanden oder heißt das Verzeichnis anders, den Pfad ausdrücklich mit `--webroot=…` angeben. Die Beispiele verwenden diesen eindeutigen Pfad. Den tatsächlichen Kontopfad und PHP-CLI-Pfad beim Hostinganbieter prüfen. Dieselbe App-Konfiguration einschließlich Verschlüsselungsschlüssel beibehalten. Die PHP-CLI benötigt PDO-MySQL, mbstring, cURL und die zur bestehenden Verschlüsselung passende Sodium-/OpenSSL-Erweiterung sowie ausgehenden HTTPS-Zugriff.

### Vorschau und Zeitplan

Zunächst ohne Netzwerkabrufe und ohne Kalenderänderungen prüfen:

```sh
/usr/bin/php /users/cool-rooms/cron/sync_timetables.php --webroot=/users/cool-rooms/public_html --dry-run
```

Die Vorschau zählt geeignete Kalender; sie prüft noch nicht die Erreichbarkeit oder Gültigkeit der Links. Ohne `--dry-run` werden die gespeicherten Adressen tatsächlich abgerufen. Der Zeitpunkt erscheint anschließend bei **Letzter Import** in der Stundenplanansicht.

Benutzer-Crontab für täglich 07:00 Uhr:

```cron
0 7 * * * /usr/bin/php /users/cool-rooms/cron/sync_timetables.php --webroot=/users/cool-rooms/public_html >> /users/cool-rooms/logs/webuntis_sync.log 2>&1
```

Im Hosting-Zeitplan **Europe/Vienna** einstellen. Entscheidend ist die Zeitzone des Cron-Dienstes, nicht die PHP-Zeitzone. Unterstützt der Anbieter `CRON_TZ`, kann oberhalb des Eintrags `CRON_TZ=Europe/Vienna` stehen. Details: [crontab-Handbuch](https://man7.org/linux/man-pages/man5/crontab.5.html). Den Ordner `logs/` vorher anlegen und für den Cron-Benutzer beschreibbar machen; sonst startet bereits die Ausgabeumleitung nicht. Nicht als root ausführen. Der Cron-Benutzer muss die App-Konfiguration lesen und in `cron/` die Sperrdatei anlegen können. Logs außerhalb von `public_html/` aufbewahren und regelmäßig rotieren.

Jeder Abruf ist auf 20 Sekunden und 2 MB begrenzt; die Kalender werden nacheinander bearbeitet. Bei vielen Konten entsprechend Ausführungszeit im Hosting einplanen. Eine lokale Sperrdatei verhindert überlappende Läufe derselben Skriptinstallation. Nur eine Installation als Scheduler verwenden. Die Sperrdatei während eines laufenden Jobs nicht löschen. Fehler betreffen nur den jeweiligen Kalender: Der vorherige Import bleibt erhalten, andere Konten werden weiterbearbeitet. Änderungen oder Löschungen während des Abrufs werden nicht überschrieben; solche Konflikte werden gezählt und beim nächsten Lauf mit dem dann aktuellen Stand erneut geprüft.

Die Ausgabe enthält nur Anzahlen und bei Fehlern die interne Lehrkraft-ID, keine Namen, Adressen, Kennwörter oder Kalenderinhalte. `eligible` zählt geeignete Kalender, `updated` aktualisierte, `skipped` ausgelassene, `conflicts` zwischenzeitlich geänderte und `failed` fehlgeschlagene Kalender. Exit-Code 0 bedeutet erfolgreich (auch bei gezählten Konflikten oder übersprungenem Parallelaufruf), 1 mindestens einen Fehler, 2 ungültige Optionen. `--help` zeigt die Aufrufhilfe. Ein gültiger leerer Kalender ersetzt alte Termine ebenfalls.

Integrationstest mit simulierten Abrufen, ohne WebUntis-Netzwerkzugriff: `COOL_ROOMS_TEST_ROOT=/tmp/app-kopie php tests/timetable_sync_test.php`. Ausschließlich eine separate Kopie mit eigener anfangs leerer Datenbank namens `cool_rooms_timetable_test` verwenden. Die Teständerungen werden zurückgerollt.

Das Hochladen der Dateien allein legt noch keinen Zeitplan an. Der Eintrag muss im Hosting eingerichtet werden; der bisherige nächtliche Tagesreset bleibt ein separater Job.

## Automatischer Tagesreset per Cron

Ab Version 1.22 kann `cron/reset_daily.php` dieselben Tagesdaten wie **Administration → Alle Raumzuordnungen löschen** zurücksetzen. Das Skript läuft ausschließlich über PHP auf der Kommandozeile; Webaufrufe werden mit HTTP 404 abgewiesen. Die geschützte Adminseite wird nicht per HTTP aufgerufen. Es werden keine Admin-Zugangsdaten und kein öffentliches Reset-Token benötigt.

Der Reset löscht alle Raumzuordnungen der Schüler:innen, die Standorte der Lehrkräfte und deren verantwortliche Klassen. Alle Schüler:innen werden wieder auf **anwesend** gesetzt. Benutzerkonten, Klassen, zugewiesene Klassenräume, Räume, Passwörter, Kiosk-PINs und Kiosk-Gültigkeitszeiträume bleiben erhalten. Bestehende Ereignisse werden nicht pauschal gelöscht; die übliche Aufbewahrung des Ereignisprotokolls bleibt unverändert.

Reset und die zugehörigen Ereigniseinträge werden gemeinsam in einer Datenbanktransaktion durchgeführt. Bei einem Fehler wird die Transaktion zurückgerollt. Live-Ansichten erhalten nach erfolgreichem Abschluss das bisherige `reset`-Ereignis. Im Admin-Ereignisprotokoll ist der Vorgang als **Automatik: COOL-Tag zurückgesetzt** mit dem Ausführenden **Automatischer Tagesreset** erkennbar.

### Dateien auf dem Server

Den zusätzlichen Ordner `cron/` neben `www/` hochladen, nicht in das öffentliche Dokumentenverzeichnis. Das Skript lädt die vorhandene Konfiguration aus `../www/config.php`; die Zugangsdaten werden nicht nochmals eingetragen.

```text
/users/cool-rooms/
├── cron/
│   └── reset_daily.php
├── logs/                       selbst anlegen, nicht öffentlich
└── www/                        Dokumentenverzeichnis der Domain
    ├── config.php              lokale Datei erhalten
    └── lib/daily_reset.php
```

Bei einem Update müssen auch `www/lib/events.php`, `www/lib/daily_reset.php` und `www/admin/reset.php` aus derselben Version übernommen werden. Der Installer wird nicht erneut ausgeführt. Vorher wie bei jedem Update Dateien und Datenbank sichern.

### Erst prüfen und dann planen

Die folgenden Pfade sind Beispiele und müssen an den tatsächlichen Webspace angepasst werden. Auch der Pfad zur PHP-CLI (PHP 8.0 oder neuer mit PDO-MySQL) wird vom Hostinganbieter vorgegeben.

Zunächst eine Vorschau ohne Änderungen ausführen:

```sh
/usr/bin/php /users/cool-rooms/cron/reset_daily.php --dry-run
```

Ohne `--dry-run` erfolgt der Reset sofort. Ein erfolgreicher Aufruf liefert Exit-Code 0 und die Anzahl der gelöschten Zuordnungen beziehungsweise wieder anwesend gesetzten Schüler:innen. Fehler liefern Exit-Code 1, ungültige Optionen Exit-Code 2. `--help` zeigt eine kurze Hilfe. Wiederholte erfolgreiche Aufrufe sind möglich; bereits leere Zuordnungen bleiben leer.

### Benutzer Crontab täglich um 03 Uhr

Im Hosting-Verwaltungsbereich beziehungsweise mit `crontab -e` eine tägliche Ausführung einrichten:

```cron
0 3 * * * /usr/bin/php /users/cool-rooms/cron/reset_daily.php >> /users/cool-rooms/logs/reset_daily.log 2>&1
```

`0 3 * * *` bedeutet täglich um 03:00 Uhr in der **Zeitzone des Cron-Dienstes**. Empfohlen wird `Europe/Vienna`; die Zeitzone der PHP-Ausgabe allein verändert den Ausführungszeitpunkt nicht. Wenn der Hostinganbieter `CRON_TZ` unterstützt, kann oberhalb des Eintrags zusätzlich `CRON_TZ=Europe/Vienna` stehen. Andernfalls die Zeitzone im Hosting-Verwaltungsbereich einstellen oder den Serveradministrator fragen. 03:00 Uhr vermeidet die bei der österreichischen Zeitumstellung problematische 02-Uhr-Stunde.

Das Verzeichnis `logs/` muss vorher angelegt werden und für den Cron-Benutzer beschreibbar sein. Kann die Shell die Logdatei nicht öffnen, wird das Skript nicht gestartet. Logdatei außerhalb des Webverzeichnisses aufbewahren und regelmäßig rotieren. Nach der ersten Nacht Logdatei und Admin-Ereignisprotokoll prüfen. Die Veröffentlichung auf GitHub richtet noch keinen Cron-Job auf dem Schulserver ein; dieser Schritt erfolgt im Hosting.

## CSV-Import

Der Schüler:innenimport erwartet eine UTF-8-Datei mit den Spalten:

```text
Vorname;Nachname;Mailadresse;Klasse
```

Komma- und Semikolondateien werden erkannt. Die Bezeichnungen der Kopfzeile müssen den erwarteten Feldern entsprechen; eine eventuell vorhandene UTF-8-BOM wird berücksichtigt.

## Sicherheit und Datenschutz

COOL Rooms verarbeitet personenbezogene Daten von Schüler:innen und Lehrkräften. Die betreibende Schule ist für Rechtsgrundlage, Zugriffsregeln, Datensicherung, Aufbewahrungsfristen und Datenschutzhinweise verantwortlich.

Für den produktiven Betrieb werden mindestens folgende Maßnahmen empfohlen:

- ausschließlich HTTPS verwenden
- Datenbankzugang nur für die benötigte Datenbank berechtigen
- sichere, individuelle Passwörter vergeben
- `config.php` vor direktem Webzugriff schützen
- Setup-Kennwort und Datenbankserver ausschließlich über die externe Installationsdatei bereitstellen
- den Sperrmarker `<Geheimnisdatei>.complete` bei Updates erhalten und `install.php` nach der Einrichtung nach Möglichkeit entfernen
- regelmäßige Datenbank- und Dateisicherungen erstellen
- Ereignisprotokolle und Kiosk-Auswertungen regelmäßig prüfen und gemäß dem eigenen Löschkonzept bereinigen

## Projektstruktur

Die statische Projektwebseite liegt in `docs/` und wird über GitHub Pages unter <https://chrismey-71.github.io/cool-rooms/> veröffentlicht. Die Screenshots stammen aus einer lokalen Beispielinstallation mit ausschließlich fiktiven Daten. Es gibt derzeit keine öffentliche Live-Demo. GitHub Pages veröffentlicht nur `docs/`, nicht die PHP-Anwendung unter `www/`.

```text
cron/
└── reset_daily.php   Optionaler Tagesreset per PHP-CLI
www/
├── account/          Kontoeinstellungen
├── admin/            Administrationsbereich
├── assets/           Styles, JavaScript, Logos und App-Icons
├── lib/              Datenbank-, Authentifizierungs- und Hilfsfunktionen
├── partials/         Gemeinsamer Kopf- und Fußbereich
├── student/          Schüler:innenbereich
├── teacher/          Lehrkräftebereich
├── install.php       Webinstaller
├── kiosk.php         Kiosk-Einstieg
└── index.php         Anmeldung
```

## Lizenz

COOL Rooms wird unter der [MIT-Lizenz](LICENSE) veröffentlicht. Die Software darf verwendet, verändert und weitergegeben werden, sofern der Urheberrechts- und Lizenzhinweis erhalten bleibt.
