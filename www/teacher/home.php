<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/kiosk_access.php';
require_once __DIR__ . '/../lib/timetable.php';

$u = require_teacher();
if ($u['role'] !== 'teacher') redirect(url('/admin/home.php'));
$pdo = db();
$timetable=timetable_load($pdo,(int)$u['id']);
$previewDate=is_string($_GET['timetable_date'] ?? null)?$_GET['timetable_date']:'';
$previewOffset=filter_var($_GET['timetable_offset'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2000]]);
$preview=timetable_preview($timetable,$previewDate,$previewOffset===false?null:$previewOffset);
$upcoming=$preview['events'];
$previewUrl=fn(string $date,?int $offset=null)=>url('/teacher/home.php').'?'.http_build_query(array_filter(['timetable_date'=>$date,'timetable_offset'=>$offset],fn($v)=>$v!==null)).'#timetable';

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
  <?php if(timetable_has_import($timetable)): ?>
  <section class="card timetable-preview" id="timetable" aria-label="Mein Stundenplan">
    <div class="timetable-preview__heading"><h3>Mein Stundenplan</h3><a href="<?= h(url('/teacher/timetable.php')) ?>">Alle Termine &amp; Einstellungen</a></div>
    <div class="timetable-preview__navigation">
      <nav class="timetable-preview__day" aria-label="Stundenplantag">
        <a href="<?= h($previewUrl($preview['date']->modify('-1 day')->format('Y-m-d'))) ?>" aria-label="Vorheriger Tag" title="Vorheriger Tag">&lsaquo;</a>
        <strong><?= h($preview['date']->format('d.m.Y')) ?></strong>
        <a href="<?= h($previewUrl($preview['date']->modify('+1 day')->format('Y-m-d'))) ?>" aria-label="Nächster Tag" title="Nächster Tag">&rsaquo;</a>
        <a class="timetable-preview__today" href="<?= h(url('/teacher/home.php').'#timetable') ?>">Heute</a>
      </nav>
      <?php if($preview['total']): ?><nav class="timetable-preview__paging" aria-label="Stunden des Tages">
        <?php if($preview['previous']!==null): ?><a href="<?= h($previewUrl($preview['date']->format('Y-m-d'),$preview['previous'])) ?>" aria-label="Frühere Stunde anzeigen" title="Frühere Stunde anzeigen">&lsaquo;</a><?php else: ?><span class="timetable-preview__disabled" aria-hidden="true">&lsaquo;</span><?php endif; ?>
        <span><?= $preview['offset']+1 ?>–<?= min($preview['offset']+3,$preview['total']) ?> von <?= $preview['total'] ?></span>
        <?php if($preview['next']!==null): ?><a href="<?= h($previewUrl($preview['date']->format('Y-m-d'),$preview['next'])) ?>" aria-label="Spätere Stunde anzeigen" title="Spätere Stunde anzeigen">&rsaquo;</a><?php else: ?><span class="timetable-preview__disabled" aria-hidden="true">&rsaquo;</span><?php endif; ?>
      </nav><?php endif; ?>
    </div>
    <?php if($upcoming): ?>
    <div class="timetable-preview__tiles">
      <?php foreach($upcoming as $event): $data=$timetable; require __DIR__.'/../partials/timetable_actions.php'; endforeach; ?>
    </div>
    <?php else: ?><p class="small">Keine importierten Termine für diesen Tag.</p><?php endif; ?>
  </section>
  <?php else: ?>
  <section class="timetable-intro" aria-label="Mein Stundenplan einrichten">
    <div><h3>Mein Stundenplan</h3><p>Noch kein Import</p></div>
    <a href="<?= h(url('/teacher/timetable.php')) ?>" aria-label="WebUntis-Kalender importieren">Importieren <span aria-hidden="true">&rsaquo;</span></a>
  </section>
  <?php endif; ?>
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
