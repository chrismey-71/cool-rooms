<p align="center">
  <img src="www/assets/cool-rooms-logo.svg" width="112" height="112" alt="COOL Rooms Logo">
</p>

<h1 align="center">COOL Rooms</h1>

<p align="center"><strong>Buche deinen Raum</strong></p>

<p align="center"><a href="https://chrismey-71.github.io/cool-rooms/">Projektwebseite mit Funktionsüberblick und Screenshots</a></p>

COOL Rooms ist eine webbasierte Raumorganisation für Schulen. Schüler:innen wählen ihren aktuellen Aufenthaltsraum, Lehrkräfte geben ihren eigenen Standort bekannt und behalten verantwortliche Klassen im Blick. Administrator:innen verwalten Räume, Klassen, Benutzerkonten, Kiosk-Zugänge und das Erscheinungsbild.

Die Anwendung ist für den Betrieb auf einem klassischen PHP-/MariaDB-Webspace ausgelegt und kann als Progressive Web App auf dem Home-Bildschirm eines Smartphones gespeichert werden.

## Funktionsumfang

### Schüler:innen

- Schnelle Raumwahl mit frei sortierbaren Gruppen wie Etage, Ort oder Funktion
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

Die Datei `www/config.sample.php` zeigt die unterstützten Grundeinstellungen. `base_url` bleibt leer, wenn COOL Rooms direkt unter einer Domain betrieben wird, oder enthält beispielsweise `/cool-rooms`, wenn die App in einem Unterverzeichnis liegt.

## Kioskmodus

Der Kioskmodus ist für gemeinsam genutzte Schulcomputer gedacht. Ein Administrationscode kann Zugriff auf alle Klassen ermöglichen; Lehrkräfte können zusätzlich einen PIN für ihre verantwortliche Klasse bereitstellen. Schüler:innen wählen am zentralen Computer ihren Namen und anschließend ihren Raum.

Eine ausschließlich über den Kiosk erfolgte Anmeldung bietet nur Raumwahl und Lehrkräftesuche. Nach Inaktivität kehrt die Anwendung automatisch zum Kiosk-Einstieg zurück.

Unter **Administration → Login-Schutz** können die erlaubten Fehlversuche und die Sperrdauer festgelegt werden. Zulässig sind 3 bis 20 Fehlversuche und 1 bis 30 Minuten Sperrdauer. Die Einstellung gilt für normale Logins und Kiosk-Codeprüfungen; sie ändert keinen gespeicherten PIN und macht keinen COOL-PIN dauerhaft ungültig.

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
