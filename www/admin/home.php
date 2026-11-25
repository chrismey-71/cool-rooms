<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

$u = require_admin();

require __DIR__ . '/../partials/header.php';
?>
<div class="card admin-dashboard">
  <h2>Administration</h2>
  <p class="small">Verwandte Bereiche sind farblich gruppiert, damit Raumsteuerung, Schulorganisation und Auswertung schneller auffindbar sind.</p>

  <div class="admin-sections">
    <section class="admin-group admin-group--rooms">
      <div class="admin-group__header">
        <span class="admin-group__badge">Raumsteuerung</span>
        <h3>Räume, Belegung &amp; Kiosk</h3>
        <p class="small">Für verfügbare Räume, Live-Belegung und Kiosk-Zugänge.</p>
      </div>

      <div class="grid admin-grid">
        <div class="card admin-tile admin-tile--rooms">
          <span class="admin-tile__tag">Räume</span>
          <h4>Räume</h4>
          <p><a href="<?= h(url('/admin/rooms.php')) ?>">Verwalten</a></p>
          <p class="small">Kapazitäten, Sichtbarkeit und Spezialräume pflegen.</p>
        </div>

        <div class="card admin-tile admin-tile--rooms">
          <span class="admin-tile__tag">Live</span>
          <h4>Raumbelegung</h4>
          <p><a href="<?= h(url('/admin/live.php')) ?>">Alle Schüler:innen in Räumen anzeigen</a></p>
          <p class="small">Mit Klassenfilter inkl. „nicht eingebucht“.</p>
        </div>

        <div class="card admin-tile admin-tile--rooms">
          <span class="admin-tile__tag">Login</span>
          <h4>Kiosk</h4>
          <p><a href="<?= h(url('/admin/kiosk.php')) ?>">Kiosk-Login (Tagescode)</a></p>
          <p class="small">Aktivieren, Tagescode setzen und Datum festlegen.</p>
        </div>
      </div>
    </section>

    <section class="admin-group admin-group--classes">
      <div class="admin-group__header">
        <span class="admin-group__badge">Klassenorganisation</span>
        <h3>Klassen &amp; Schüler:innen</h3>
        <p class="small">Alles für Schuljahreswechsel, Zugangsdaten und Schülerverwaltung.</p>
      </div>

      <div class="grid admin-grid">
        <div class="card admin-tile admin-tile--classes">
          <span class="admin-tile__tag">Klasse</span>
          <h4>Klassen</h4>
          <p><a href="<?= h(url('/admin/classes.php')) ?>">Klassen, Gruppen &amp; Klassenräume verwalten</a></p>
          <p class="small">Für Schuljahreswechsel, Gruppenzuordnungen und das Entfernen ausgeschiedener Klassen.</p>
        </div>

        <div class="card admin-tile admin-tile--classes">
          <span class="admin-tile__tag">Zugang</span>
          <h4>Klassen-Kennwörter</h4>
          <p><a href="<?= h(url('/admin/bulk_passwords.php')) ?>">Kurze Kennwörter pro Klasse setzen</a></p>
          <p class="small">Erzeugt Druckliste, Passwortänderung wird erzwungen.</p>
        </div>

        <div class="card admin-tile admin-tile--classes">
          <span class="admin-tile__tag">Stammdaten</span>
          <h4>Schüler:innen</h4>
          <p><a href="<?= h(url('/admin/students.php')) ?>">Verwalten</a></p>
          <p><a href="<?= h(url('/admin/import_students.php')) ?>">Import (CSV)</a></p>
        </div>
      </div>
    </section>

    <section class="admin-group admin-group--ops">
      <div class="admin-group__header">
        <span class="admin-group__badge">Lehrkräfte &amp; Überblick</span>
        <h3>Lehrer:innen, COOL-Tag &amp; Eventauswertung</h3>
        <p class="small">Für Personalpflege, globale Rücksetzungen und Nachvollziehbarkeit.</p>
      </div>

      <div class="grid admin-grid">
        <div class="card admin-tile admin-tile--ops">
          <span class="admin-tile__tag">Personal</span>
          <h4>Lehrer:innen</h4>
          <p><a href="<?= h(url('/admin/teachers.php')) ?>">Verwalten</a></p>
          <p class="small">Zugänge, Verantwortlichkeiten und Lehrkräfte-Daten pflegen.</p>
        </div>

        <div class="card admin-tile admin-tile--ops">
          <span class="admin-tile__tag">Zuordnung</span>
          <h4>WebUntis-Kürzel zuordnen</h4>
          <p><a href="<?= h(url('/admin/webuntis.php#rooms')) ?>">Räume zuordnen</a></p>
          <p><a href="<?= h(url('/admin/webuntis.php#classes')) ?>">Klassen zuordnen</a></p>
          <p><a href="<?= h(url('/admin/webuntis.php#teachers')) ?>">Lehrer:innen zuordnen</a></p>
          <p class="small">Zentrale Kürzel für alle persönlichen Stundenpläne pflegen.</p>
        </div>

        <div class="card admin-tile admin-tile--ops">
          <span class="admin-tile__tag">Rücksetzen</span>
          <h4>COOL-Tag</h4>
          <p><a href="<?= h(url('/admin/reset.php')) ?>">Alle Raumzuordnungen löschen</a></p>
          <p class="small">Reset = alle sind wieder „nicht eingebucht“.</p>
        </div>

        <div class="card admin-tile admin-tile--ops">
          <span class="admin-tile__tag">Prüfen</span>
          <h4>Eventauswertung</h4>
          <p><a href="<?= h(url('/admin/events.php')) ?>">Filtern &amp; prüfen</a></p>
          <p class="small">Kioskmodus, Klasse, Schüler:in, Gerät/OS/IP.</p>
        </div>

        <div class="card admin-tile admin-tile--ops">
          <span class="admin-tile__tag">Design</span>
          <h4>Erscheinungsbild</h4>
          <p><a href="<?= h(url('/admin/appearance.php')) ?>">Titel, Logo und Farben anpassen</a></p>
          <p class="small">Darstellung für die eigene Schule konfigurieren.</p>
        </div>

        <div class="card admin-tile admin-tile--ops">
          <span class="admin-tile__tag">Sicherheit</span>
          <h4>Login-Schutz</h4>
          <p><a href="<?= h(url('/admin/security.php')) ?>">Fehlversuche &amp; Sperrdauer</a></p>
          <p class="small">Grenzen für Login und Kiosk-Zugang festlegen.</p>
        </div>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
