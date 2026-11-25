<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_groups.php';
require_once __DIR__ . '/../lib/timetable.php';

$u = require_teacher();
$pdo = db();

function teacher_responsible_class(PDO $pdo, int $teacherId): ?string {
  $stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=?");
  $stmt->execute([$teacherId]);
  $class = $stmt->fetchColumn();
  return $class ? (string)$class : null;
}

ensure_room_group_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $roomId = (int)($_POST['room_id'] ?? 0);

  if ($roomId === 0) {
    $stmt = $pdo->prepare("DELETE FROM teacher_locations WHERE teacher_id=?");
    $stmt->execute([(int)$u['id']]);

    emit_event('teacher_location', [
      'teacher_id' => (int)$u['id'],
      'teacher_name' => (string)($u['first_name'] . ' ' . $u['last_name']),
      'room_id' => 0,
      'room_name' => '',
      'updated_at' => '',
    ]);

    flash_set('success', 'Du bist jetzt „nicht eingebucht“.');
    if (teacher_responsible_class($pdo, (int)$u['id']) === null) {
      redirect(url('/teacher/set_class.php'));
    }
    redirect(url('/teacher/location.php'));
  }

  $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE id=? AND is_active=1");
  $stmt->execute([$roomId]);
  $room = $stmt->fetch();

  if (!$room) {
    flash_set('error', 'Ungültiger Raum.');
    redirect(url('/teacher/location.php'));
  }

  $stmt = $pdo->prepare("
    INSERT INTO teacher_locations (teacher_id, room_id) VALUES (?,?)
    ON DUPLICATE KEY UPDATE room_id=VALUES(room_id)
  ");
  $stmt->execute([(int)$u['id'], $roomId]);

  $stmt = $pdo->prepare("SELECT updated_at FROM teacher_locations WHERE teacher_id=?");
  $stmt->execute([(int)$u['id']]);
  $ts = (string)($stmt->fetchColumn() ?: '');

  emit_event('teacher_location', [
    'teacher_id' => (int)$u['id'],
    'teacher_name' => (string)($u['first_name'] . ' ' . $u['last_name']),
    'room_id' => (int)$roomId,
    'room_name' => (string)($room['name'] ?? ''),
    'updated_at' => $ts,
  ]);

  flash_set('success', 'Raum gespeichert.');
  if (teacher_responsible_class($pdo, (int)$u['id']) === null) {
    redirect(url('/teacher/set_class.php'));
  }
  redirect(url('/teacher/location.php'));
}

$rooms = $pdo->query("
  SELECT r.id, r.name, r.is_teacher_only, r.group_type, r.group_name,
         COALESCE(rg.group_color, r.group_color) AS group_color,
         COALESCE(rgt.sort_order, 9999) AS type_sort_order,
         COALESCE(rg.sort_order, 9999) AS group_sort_order
  FROM rooms r
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = r.group_type
  LEFT JOIN room_group_settings rg ON rg.group_type = r.group_type AND rg.group_name = r.group_name
  WHERE r.is_active=1
  ORDER BY type_sort_order, group_sort_order, COALESCE(NULLIF(r.group_name,''), 'zzzz'), r.is_teacher_only DESC, r.name
")->fetchAll();

$roomGroups = [];
foreach ($rooms as $room) {
  $groupName = trim((string)($room['group_name'] ?? ''));
  $groupType = (string)($room['group_type'] ?? 'other');
  if ($groupName === '') {
    $groupName = 'Weitere Räume';
    $groupType = 'other';
  }

  $groupKey = $groupType . '|' . $groupName;
  if (!isset($roomGroups[$groupKey])) {
    $color = trim((string)($room['group_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
      $color = fallback_room_group_color($groupKey);
    }
    $roomGroups[$groupKey] = [
      'type' => $groupType,
      'name' => $groupName,
      'color' => $color,
      'rooms' => [],
    ];
  }
  $roomGroups[$groupKey]['rooms'][] = $room;
}

$stmt = $pdo->prepare("
  SELECT r.id, r.name, tl.updated_at
  FROM teacher_locations tl
  JOIN rooms r ON r.id = tl.room_id
  WHERE tl.teacher_id=?
");
$stmt->execute([(int)$u['id']]);
$current = $stmt->fetch() ?: null;

$responsibleClass = teacher_responsible_class($pdo, (int)$u['id']);
$timetable=$u['role']==='teacher' ? timetable_load($pdo,(int)$u['id']) : ['enabled'=>false,'events'=>[]];
$suggestedRoom=$timetable['enabled'] ? timetable_targets($pdo,$timetable,timetable_current($timetable))['room'] : 0;

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Mein Raum</h2>
  <?php if($suggestedRoom): ?><p class="flash info">Der aktuelle Stundenplanraum ist vorausgewählt. Erst „Speichern“ übernimmt ihn. Du kannst einen anderen Raum wählen.</p><?php endif; ?>
  <p class="small">Damit können Schüler:innen und Lehrkräfte sehen, wo du gerade bist. „Lehrerzimmer“ ist nur für Lehrkräfte auswählbar.</p>

  <div class="card">
    <h3>Aktueller Standort</h3>
    <?php if ($current): ?>
      <p><span class="badge ok"><?= h($current['name']) ?></span></p>
      <p class="small">Stand: <?= h($current['updated_at'] ?? '') ?></p>
    <?php else: ?>
      <p><span class="badge warn">nicht eingebucht</span></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Raum schnell auswählen</h3>
    <p class="small">Tippe oder klicke direkt auf eine Raumkachel. Räume sind nach Etage, Ort oder Funktion gruppiert.</p>
    <div class="student-room-groups teacher-room-groups" id="teacherRoomQuickSelect">
      <?php foreach ($roomGroups as $group): ?>
        <section class="student-room-group" style="--room-group-color:<?= h((string)$group['color']) ?>">
          <div class="student-room-group__header">
            <span class="student-room-group__label"><?= h(room_group_type_label((string)$group['type'])) ?></span>
            <h4><?= h((string)$group['name']) ?></h4>
          </div>
          <div class="student-room-card-grid">
            <?php foreach ($group['rooms'] as $room): ?>
              <?php
                $isCurrent = $current && (int)$current['id'] === (int)$room['id'];
                $isTeacherOnly = (int)($room['is_teacher_only'] ?? 0) === 1;
              ?>
              <div class="student-room-card room-row <?= $isCurrent ? 'is-current' : '' ?> <?= $isTeacherOnly ? 'is-teacher-room' : '' ?>"
                   role="button"
                   data-room-id="<?= (int)$room['id'] ?>"
                   data-full="0"
                   aria-label="Raum <?= h((string)$room['name']) ?> auswählen">
                <div class="student-room-card__top">
                  <strong><?= h((string)$room['name']) ?></strong>
                  <?php if ($isCurrent): ?>
                    <span class="badge ok">Aktuell gewählt</span>
                  <?php endif; ?>
                  <?php if ($isTeacherOnly): ?>
                    <span class="badge warn">nur Lehrer:innen</span>
                  <?php endif; ?>
                </div>
                <div class="student-room-card__meta">
                  <?php if (!empty($room['group_name'])): ?>
                    <span class="badge"><?= h(room_group_type_label((string)($room['group_type'] ?? 'other'))) ?>: <?= h((string)$room['group_name']) ?></span>
                  <?php else: ?>
                    <span class="badge">ohne Gruppe</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h3>Raum auswählen / ändern</h3>
    <form method="post" id="teacherRoomSelectForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Raum</label>
      <select name="room_id">
        <option value="0">nicht eingebucht</option>
        <?php foreach ($rooms as $r): ?>
          <?php
            $label = (string)$r['name'];
            if (!empty($r['group_name'])) {
              $label .= ' – ' . room_group_type_label((string)($r['group_type'] ?? 'other')) . ': ' . (string)$r['group_name'];
            }
            if ((int)($r['is_teacher_only'] ?? 0) === 1) {
              $label .= ' (nur Lehrer:innen)';
            }
          ?>
          <option value="<?= (int)$r['id'] ?>" <?= ($suggestedRoom ?: (int)($current['id'] ?? 0))===(int)$r['id'] ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
    </form>
  </div>

  <div class="card">
    <h3>Nächster Schritt</h3>
    <?php if ($responsibleClass): ?>
      <p>Verantwortliche Klasse: <span class="badge"><?= h((string)$responsibleClass) ?></span></p>
      <p><a href="<?= h(url('/teacher/view_class.php')) ?>">Klasse live anzeigen</a></p>
    <?php else: ?>
      <p class="small">Wenn dein Raum passt, wähle als Nächstes deine verantwortliche Klasse.</p>
      <p><a href="<?= h(url('/teacher/set_class.php')) ?>">Klasse wählen</a></p>
    <?php endif; ?>
  </div>
</div>
<script>
(() => {
  const quickSelect = document.getElementById('teacherRoomQuickSelect');
  const form = document.getElementById('teacherRoomSelectForm');
  const select = form ? form.querySelector('select[name="room_id"]') : null;
  if (!quickSelect || !form || !select) return;

  quickSelect.addEventListener('click', (event) => {
    const room = event.target.closest('.room-row');
    if (!room) return;
    const roomId = room.getAttribute('data-room-id');
    if (!roomId) return;
    select.value = roomId;
    form.submit();
  });

  quickSelect.querySelectorAll('.room-row').forEach((room) => {
    room.tabIndex = 0;
    room.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      room.click();
    });
  });
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
