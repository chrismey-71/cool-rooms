# Sicherheitsrichtlinie

## Unterstützte Versionen

COOL Rooms befindet sich in aktiver Entwicklung. Sicherheitskorrekturen werden grundsätzlich nur für die jeweils aktuelle veröffentlichte Version bereitgestellt.

| Version | Unterstützt |
| --- | --- |
| 1.23 | Ja |
| Ältere Versionen | Nein |

Betreiber:innen sollten vor einem Update die Datenbank, `www/config.php` und eigene Dateien unter `www/assets/branding/` sichern.

## Sicherheitslücken melden

Bitte veröffentliche vermutete Sicherheitslücken nicht als öffentliches GitHub-Issue und teile dort keine Zugangsdaten, personenbezogenen Daten oder unmittelbar nutzbaren Angriffsschritte.

Nutze bevorzugt die private Sicherheitsmeldung im GitHub-Repository unter **Security → Report a vulnerability**, sofern diese Funktion aktiviert ist. Ist kein privater Meldeweg verfügbar, kontaktiere die Repository-Verantwortlichen zunächst ohne technische Details über die im GitHub-Profil angegebene Kontaktmöglichkeit.

Eine hilfreiche Meldung enthält:

- betroffene COOL-Rooms-Version
- betroffene Seite oder Funktion
- Voraussetzungen und nachvollziehbare Schritte
- erwartetes und tatsächliches Verhalten
- mögliche Auswirkungen
- eine vorgeschlagene Korrektur, falls vorhanden

Verwende ausschließlich Testdaten und greife nicht auf fremde Konten oder echte personenbezogene Daten zu. Der Eingang einer Meldung wird nach Möglichkeit bestätigt. Eine feste Reaktions- oder Behebungsfrist kann für dieses nicht kommerzielle Projekt nicht zugesichert werden.

## System und Geltungsbereich

Diese Richtlinie gilt für den Anwendungscode im Ordner `www/` sowie für den mitgelieferten Installer und die Standardkonfiguration. COOL Rooms ist eine PHP-/MariaDB-Webanwendung für Schulen und kann öffentlich über das Internet erreichbar sein.

Besonders schützenswert sind:

- Konten, Rollen und Sitzungen von Schüler:innen, Lehrkräften und Administrator:innen
- Namen, Klassenzugehörigkeiten, Anwesenheitsstatus und aktuelle Raumzuordnungen
- Passwörter, temporäre PINs und Kiosk-Zugangsdaten
- Datenbankzugangsdaten in `www/config.php`
- Ereignis- und Kioskprotokolle
- administrative Aktionen und Änderungen an Raum- oder Klassenzuordnungen

Die Konfiguration des Webservers, des Betriebssystems, der Datenbank und der Domain liegt außerhalb des Repositorys. Sicherheitsprobleme im Anwendungscode bleiben dennoch relevant, wenn sie durch eine übliche unterstützte Installation erreichbar sind.

## Bedrohungsmodell und Vertrauensgrenzen

Als nicht vertrauenswürdig gelten sämtliche Eingaben aus HTTP-Anfragen, Formularen, URLs, Cookies, CSV-Dateien, hochgeladenen Logos und Kiosk-Eingaben.

Die Anwendung unterscheidet folgende Berechtigungsgrenzen:

- Nicht angemeldete Personen dürfen keine geschützten Daten abrufen oder verändern.
- Schüler:innen dürfen nur die für ihre Rolle vorgesehenen Ansichten und Aktionen verwenden.
- Kiosk-Sitzungen von Schüler:innen sind auf Raumwahl und Lehrkräftesuche beschränkt.
- Lehrkräfte dürfen nur Lehrkräftefunktionen sowie die ihnen zugeordnete verantwortliche Klasse verwalten.
- Administrative Funktionen erfordern ein Administrationskonto oder eine ausdrücklich erteilte Administrationsberechtigung.
- Direkter Zugriff auf Konfigurationsdateien und interne Betriebsdaten muss verhindert werden.

Ein Kiosk-PIN erleichtert die beaufsichtigte Nutzung eines gemeinsamen Schulcomputers. Er ersetzt kein persönliches Konto und darf keine weitergehenden Rechte als den vorgesehenen Kioskzugriff vermitteln.

## Sicherheitsanforderungen

Folgende Eigenschaften müssen erhalten bleiben:

- Jede geschützte Seite prüft Anmeldung und erforderliche Rolle serverseitig.
- Schreibende Aktionen prüfen Berechtigung und CSRF-Token serverseitig.
- Datenbankwerte aus nicht vertrauenswürdigen Quellen werden über PDO-Parameter gebunden und nicht in SQL-Text eingesetzt.
- Passwörter und Kiosk-Zugangscodes werden mit der PHP Password API gehasht und geprüft; Codes, die im Lehrerbereich erneut angezeigt werden müssen, liegen zusätzlich nur verschlüsselt vor.
- Fehlversuche bei Login und Kiosk werden serverseitig begrenzt. Die Admin-Einstellung erlaubt 3 bis 20 Versuche und 1 bis 30 Minuten Sperrdauer.
- Normale Anmeldungen werden pro Konto, Kiosk-Codeprüfungen pro signierter Browserkennung begrenzt. Gemeinsame Schul-IPs dürfen nicht durch niedrige, kontoübergreifende Fehlversuchszähler minutenlang gesperrt werden.
- Gegen massenhaft neue Kontonamen oder Browserkennungen gibt es separate kurze IP-Anfragebudgets; deren Fenster dürfen durch abgewiesene Anfragen nicht verlängert werden. Cookie-Löschung und verteilte Angriffe bleiben ausdrücklich Grenzen dieses Schutzes.
- Nach erfolgreicher Anmeldung oder einem Wechsel der Sitzungsidentität wird die Session-ID erneuert.
- Ausgaben aus Benutzereingaben werden passend zum Ausgabekontext maskiert.
- Datei-Uploads werden nach Typ, Größe, Inhalt und Zielpfad begrenzt.
- CSV-Importe dürfen keine Rollen- oder Klassengrenzen umgehen.
- Live-Ansichten und Ereignisdaten geben keine zusätzlichen vertraulichen Daten preis.
- Klassen-, Raum- und Benutzerkennungen werden nicht allein aufgrund übermittelter Formularwerte als berechtigt betrachtet.
- Live-Streams werden serverseitig auf Admin-, verantwortliche Klassen- und ausgewählte Raum-Scope begrenzt.
- Raumkapazitäten werden beim Schreiben transaktionsgeschützt geprüft und nicht nur in der Oberfläche angezeigt.
- Fehlerausgaben legen in der Produktion keine Zugangsdaten, SQL-Abfragen, Dateipfade oder unnötigen personenbezogenen Daten offen.
- `www/config.php`, Laufzeitdateien, Protokolle und hochgeladene Dateien gelangen nicht unbeabsichtigt in das Repository.

## Relevante Sicherheitsfunde

Insbesondere meldewürdig sind:

- Umgehungen von Anmeldung, Rollen- oder Klassenzuordnung
- SQL-Injection oder andere serverseitige Injection
- Cross-Site Scripting, CSRF oder Session-Übernahme
- Offenlegung oder Manipulation personenbezogener Daten
- Zugriff auf Passwörter, PINs, Konfiguration oder Protokolle
- unsichere Datei-Uploads oder Pfadmanipulation
- Umgehung der Einschränkungen einer Kiosk-Sitzung
- unberechtigte Änderungen an Anwesenheit, Raumzuordnung oder Benutzerkonten
- aus der Ferne auslösbare erhebliche Verfügbarkeitsprobleme mit realistischem Angriffspfad

Die Schwere richtet sich nach Erreichbarkeit, benötigten Rechten, Umfang der betroffenen Daten und Auswirkung auf Vertraulichkeit, Integrität oder Verfügbarkeit.

## Nicht als Sicherheitslücke eingestuft

Ohne zusätzliche Sicherheitsauswirkung gelten in der Regel nicht als Sicherheitslücke:

- reine Darstellungs- oder Bedienungsfehler
- fehlende Funktionen oder allgemeine Verbesserungsvorschläge
- Probleme ausschließlich auf nicht unterstützten PHP-, Datenbank- oder Browserversionen
- Angriffe, die bereits vollständigen Zugriff auf Webserver, Datenbank oder ein Administrationskonto voraussetzen und keine weitere Sicherheitsgrenze überwinden
- Schwachstellen in der individuellen Serverkonfiguration, wenn die Anwendung bei einer üblichen sicheren Konfiguration nicht betroffen ist

Solche Punkte können als normales GitHub-Issue gemeldet werden, sofern sie keine vertraulichen Informationen enthalten.

## Hinweise für Betreiber:innen

Für den produktiven Betrieb werden mindestens HTTPS, individuelle Datenbankzugänge mit minimalen Rechten, sichere Passwörter, regelmäßige Sicherungen und aktuelle PHP-/MariaDB-Versionen vorausgesetzt. Der Webinstaller verlangt ein mindestens 32 Byte langes Setup-Kennwort aus einer Datei außerhalb aller öffentlich ausgelieferten Anwendungsverzeichnisse, übernimmt den Datenbankserver ausschließlich aus dieser Datei und erzeugt nach erfolgreicher Installation einen installationsspezifischen dauerhaften Sperrmarker. Die Geheimnisdatei darf nicht im Webverzeichnis oder Repository liegen; ihr Marker mit der Endung `.complete` muss bei Updates erhalten bleiben. `install.php` sollte nach der Einrichtung zusätzlich entfernt oder unzugänglich gemacht werden. Die Schule bleibt für Rechtsgrundlage, Datenschutzinformationen, Berechtigungskonzept, Aufbewahrungsfristen und Löschung personenbezogener Daten verantwortlich.
