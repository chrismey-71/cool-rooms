<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/kiosk_access.php';

$u = require_teacher();
$pdo = db();

$stmt = $pdo->prepare("
  SELECT r.name
  FROM teacher_locations tl
  JOIN rooms r ON r.id = tl.room_id
  WHERE tl.teacher_id=?
");
$stmt->execute([(int)$u['id']]);
$currentRoom = (string)($stmt->fetchColumn() ?: '');

$stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=?");
$stmt->execute([(int)$u['id']]);
$responsibleClass = (string)($stmt->fetchColumn() ?: '');

function teacher_home_setting(PDO $pdo, string $key, string $default = ''): string {
  $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k=?");
  $stmt->execute([$key]);
  $value = $stmt->fetchColumn();
  return $value === false ? $default : (string)$value;
}

function teacher_home_valid_date_or_default(string $value, string $default): string {
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return ($dt && $dt->format('Y-m-d') === $value) ? $value : $default;
}

$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$kioskEnabled = teacher_home_setting($pdo, 'kiosk_enabled', '0') === '1';
$kioskDate = teacher_home_setting($pdo, 'kiosk_expires', $today);
$kioskValidFrom = teacher_home_valid_date_or_default(teacher_home_setting($pdo, 'kiosk_valid_from', $kioskDate ?: $today), $today);
$kioskValidTo = teacher_home_valid_date_or_default(teacher_home_setting($pdo, 'kiosk_valid_to', $kioskDate ?: $today), $kioskValidFrom);
$kioskActiveToday = $kioskEnabled && $today >= $kioskValidFrom && $today <= $kioskValidTo;
$classKioskPin = '';
$classKioskStatus = 'deaktiviert';

if ($kioskActiveToday) {
  $classKioskStatus = 'nicht gesetzt';
  if ($responsibleClass !== '') {
    try {
      $classKioskPin = kiosk_class_code($pdo, $responsibleClass, $today);
      if ($classKioskPin !== '') {
        $classKioskStatus = $classKioskPin;
      }
    } catch (Throwable $e) {
      $classKioskStatus = 'nicht gesetzt';
    }
  }
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card teacher-dashboard">
  <h2>Lehrerbereich</h2>
  <div class="teacher-current-selection" aria-label="Aktuelle Auswahl">
    <span><strong>Mein Raum:</strong> <?= h($currentRoom !== '' ? $currentRoom : 'nicht eingebucht') ?></span>
    <span><strong>Verantwortliche Klasse:</strong> <?= h($responsibleClass !== '' ? $responsibleClass : 'keine Klasse gewählt') ?></span>
    <span><strong>Klassen-Kiosk-PIN:</strong> <?= h($classKioskStatus) ?></span>
  </div>
  <p class="small">Die wichtigsten Funktionen sind nach täglicher Eingabe und ergänzenden Klassenwerkzeugen gruppiert.</p>

  <div class="teacher-sections">
    <section class="teacher-group teacher-group--day">
      <div class="teacher-group__header">
        <span class="teacher-group__badge">Aktueller Tag</span>
        <h3>Tagessteuerung</h3>
        <p class="small">Diese Bereiche werden typischerweise direkt für den laufenden COOL-Tag genutzt.</p>
      </div>

      <div class="grid teacher-grid">
        <div class="card teacher-tile teacher-tile--day">
          <span class="teacher-tile__tag">Standort</span>
          <h4>Mein Raum</h4>
          <p><a href="<?= h(url('/teacher/location.php')) ?>">Standort setzen (Raum / Lehrerzimmer)</a></p>
        </div>

        <div class="card teacher-tile teacher-tile--day">
          <span class="teacher-tile__tag">Klasse</span>
          <h4>Verantwortliche Klasse</h4>
          <p><a href="<?= h(url('/teacher/set_class.php')) ?>">Klasse auswählen</a></p>
        </div>

        <div class="card teacher-tile teacher-tile--day">
          <span class="teacher-tile__tag">Live</span>
          <h4>Klasse live anzeigen</h4>
          <p><a href="<?= h(url('/teacher/view_class.php')) ?>">Schüler:innen + Raum (Live)</a></p>
        </div>
      </div>
    </section>

    <section class="teacher-group teacher-group--tools">
      <div class="teacher-group__header">
        <span class="teacher-group__badge">Klassenwerkzeuge</span>
        <h3>Kiosk, Raumansicht &amp; Passwörter</h3>
        <p class="small">Ergänzende Funktionen für Klassenzugang, Raumkontrolle und Schüler-PINs.</p>
      </div>

      <div class="grid teacher-grid">
        <div class="card teacher-tile teacher-tile--tools">
          <span class="teacher-tile__tag">Kiosk</span>
          <h4>Klassen-Kiosk</h4>
          <p><a href="<?= h(url('/teacher/kiosk_class.php')) ?>">Kiosk-PIN für Klasse setzen</a></p>
          <p class="small">Kiosk zeigt danach nur diese Klasse.</p>
        </div>

        <div class="card teacher-tile teacher-tile--tools">
          <span class="teacher-tile__tag">Raum</span>
          <h4>Raum live anzeigen</h4>
          <p><a href="<?= h(url('/teacher/view_room.php')) ?>">Schüler:innen im Raum</a></p>
        </div>

        <div class="card teacher-tile teacher-tile--tools">
          <span class="teacher-tile__tag">PINs</span>
          <h4>Passwörter</h4>
          <p><a href="<?= h(url('/teacher/passwords.php')) ?>">Schüler-Passwörter zurücksetzen</a></p>
          <p class="small">(ohne Username)</p>
        </div>
      </div>
    </section>
  </div>

  <p class="small">Live-View nutzt <strong>Server-Sent Events (SSE)</strong>. Falls ein Proxy puffert, kann ein sanfter Reload helfen.</p>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
