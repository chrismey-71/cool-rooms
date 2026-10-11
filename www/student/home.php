<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_groups.php';
require_once __DIR__ . '/../lib/room_assignments.php';

$u = require_student();
$pdo = db();

ensure_room_group_schema($pdo);

$classRoom = null;
$classRoomId = 0;
if (!empty($u['class_name'])) {
  try {
    $stmt = $pdo->prepare("
      SELECT r.id, r.name
      FROM class_settings cs
      JOIN rooms r ON r.id = cs.home_room_id
      WHERE cs.class_name=? AND r.is_active=1 AND (r.is_teacher_only=0 OR r.is_teacher_only IS NULL)
      LIMIT 1
    ");
    $stmt->execute([(string)$u['class_name']]);
    $classRoom = $stmt->fetch() ?: null;
    $classRoomId = (int)($classRoom['id'] ?? 0);
  } catch (Throwable $e) {
    $classRoom = null;
    $classRoomId = 0;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $roomId = (int)($_POST['room_id'] ?? 0);

  if ($roomId === 0) {
    // Set to "not checked in": remove assignment
    $stmt = $pdo->prepare("DELETE FROM room_assignments WHERE student_id=?");
    $stmt->execute([(int)$u['id']]);

    emit_event('assignment', [
      'student_id' => (int)$u['id'],
      'class_name' => (string)($u['class_name'] ?? ''),
      'room_id' => 0,
      'room_name' => 'nicht eingebucht',
      'updated_at' => date('Y-m-d H:i:s'),
    ]);

    flash_set('success', 'Status gespeichert: nicht eingebucht.');
    redirect(url('/student/home.php'));
  }

  $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE id=? AND is_active=1 AND (is_teacher_only=0 OR is_teacher_only IS NULL)");
  $stmt->execute([$roomId]);
  $room = $stmt->fetch();

  if (!$room) {
    flash_set('error', 'Ungültiger Raum.');
    redirect(url('/student/home.php'));
  }

  try {
    assign_students_to_room($pdo, [(int)$u['id']], $roomId);
  } catch (RoomAssignmentCapacityException|InvalidArgumentException $e) {
    flash_set('error', $e->getMessage());
    redirect(url('/student/home.php'));
  }

  // current timestamp
  $stmt = $pdo->prepare("SELECT updated_at FROM room_assignments WHERE student_id=?");
  $stmt->execute([(int)$u['id']]);
  $ts = (string)($stmt->fetchColumn() ?: '');

  emit_event('assignment', [
    'student_id' => (int)$u['id'],
    'class_name' => (string)($u['class_name'] ?? ''),
    'room_id' => (int)$roomId,
    'room_name' => (string)($room['name'] ?? ''),
    'updated_at' => $ts,
  ]);

  flash_set('success', 'Raum gespeichert.');
  redirect(url('/student/home.php'));
}

$stmt = $pdo->prepare("
  SELECT r.id, r.name, r.capacity, r.group_type, r.group_name,
         COALESCE(rg.group_color, r.group_color) AS group_color,
         COALESCE(rgt.sort_order, 9999) AS type_sort_order,
         COALESCE(rg.sort_order, 9999) AS group_sort_order,
         COUNT(ra.student_id) AS occupied,
         CASE WHEN r.id = ? THEN 1 ELSE 0 END AS is_class_room
  FROM rooms r
  LEFT JOIN room_assignments ra ON ra.room_id = r.id
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = r.group_type
  LEFT JOIN room_group_settings rg ON rg.group_type = r.group_type AND rg.group_name = r.group_name
  WHERE r.is_active=1 AND (r.is_teacher_only=0 OR r.is_teacher_only IS NULL)
  GROUP BY r.id, r.name, r.capacity, r.group_type, r.group_name, r.group_color,
           rg.group_color, rgt.sort_order, rg.sort_order
  ORDER BY type_sort_order, group_sort_order, COALESCE(NULLIF(r.group_name,''), 'zzzz'), is_class_room DESC, r.name
");
$stmt->execute([$classRoomId]);
$rooms = $stmt->fetchAll();
$roomGroups = [];
foreach ($rooms as $room) {
  $groupName = trim((string)($room['group_name'] ?? ''));
  $groupType = trim((string)($room['group_type'] ?? ''));
  $groupKey = $groupType . '|' . $groupName;
  if ($groupName === '') {
    $groupName = 'Weitere Räume';
    $groupType = '';
    $groupKey = 'other|';
  }
  if (!isset($roomGroups[$groupKey])) {
    $color = trim((string)($room['group_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
      $color = fallback_room_group_color($groupKey);
    }
    $roomGroups[$groupKey] = [
      'type' => $groupType,
      'name' => $groupName,
      'color' => strtoupper($color),
      'rooms' => [],
    ];
  }
  $roomGroups[$groupKey]['rooms'][] = $room;
}

$stmt = $pdo->prepare("
  SELECT r.id, r.name, ra.updated_at
  FROM room_assignments ra
  JOIN rooms r ON r.id = ra.room_id
  WHERE ra.student_id=?
");
$stmt->execute([(int)$u['id']]);
$current = $stmt->fetch() ?: null;

require __DIR__ . '/../partials/header.php';
?>
<div class="card student-dashboard">
  <div class="student-hero">
    <div>
      <h2>Hallo <?= h($u['first_name']) ?></h2>
      <p class="student-hero__meta">
        <span class="student-hero__meta-item">
          <strong>Klasse:</strong>
          <span class="badge"><?= h($u['class_name'] ?? '') ?></span>
        </span>
        <span class="student-hero__meta-item">
          <strong>Dein Klassenraum ist:</strong>
          <?php if ($classRoom): ?>
            <span class="badge student-hero__classroom"><?= h((string)$classRoom['name']) ?></span>
          <?php else: ?>
            <span class="badge student-hero__classroom-empty">nicht festgelegt</span>
          <?php endif; ?>
        </span>
      </p>
    </div>
  </div>

  <div class="student-current-status <?= $current ? 'has-room' : 'no-room' ?>">
    <span class="student-current-status__label">Du bist derzeit im Raum:</span>
    <?php if ($current): ?>
      <strong><?= h((string)$current['name']) ?></strong>
      <span class="small student-current-status__detail">Stand: <?= h($current['updated_at'] ?? '') ?></span>
    <?php else: ?>
      <strong>Nicht eingebucht</strong>
      <span class="small student-current-status__detail">Wähle unten einen freien Raum aus.</span>
    <?php endif; ?>
  </div>

  <section class="student-room-picker">
    <div class="student-room-picker__header">
      <div>
        <h3>Freie Räume schnell wählen</h3>
        <p class="small">Tippe oder klicke unten auf eine freie Raumkachel. Räume sind nach Etage, Ort oder Funktion gruppiert. „∞“ bedeutet unbegrenzt.</p>
      </div>
      <span class="student-room-picker__badge">Raum antippen</span>
    </div>

    <div class="student-room-groups" id="roomStatusTable">
      <?php foreach ($roomGroups as $group): ?>
        <section class="student-room-group" style="--room-group-color:<?= h((string)$group['color']) ?>">
          <div class="student-room-group__header">
            <span class="student-room-group__label"><?= h(room_group_type_label((string)$group['type'])) ?></span>
            <h4><?= h((string)$group['name']) ?></h4>
          </div>
          <div class="student-room-card-grid">
            <?php foreach ($group['rooms'] as $rr):
              $cap = $rr['capacity'];
              $occ = (int)$rr['occupied'];
              $unlimited = ($cap === null || $cap === '' || (int)$cap === 0);
              $free = $unlimited ? null : max(((int)$cap) - $occ, 0);
              $full = (!$unlimited && $occ >= (int)$cap);
              $isCurrentRoom = $current && (int)$current['id'] === (int)$rr['id'];
              $isClassRoom = (int)($rr['is_class_room'] ?? 0) === 1;
            ?>
              <div class="student-room-card room-row <?= $full ? 'is-full' : 'is-free' ?> <?= $isCurrentRoom ? 'is-current' : '' ?> <?= $isClassRoom ? 'is-class-room' : '' ?>" role="button" data-room-id="<?= (int)$rr['id'] ?>" data-full="<?= $full ? '1' : '0' ?>">
                <div class="student-room-card__top">
                  <strong><?= h((string)$rr['name']) ?></strong>
                  <?php if ($isClassRoom): ?>
                    <span class="badge student-badge-classroom">Klassenraum</span>
                  <?php endif; ?>
                  <?php if ($isCurrentRoom): ?>
                    <span class="badge ok student-badge-current">Aktuell gewählt</span>
                  <?php endif; ?>
                </div>
                <div class="student-room-card__meta">
                  <?php if ($unlimited): ?>
                    <span class="badge"><?= h((string)$occ) ?> / ∞</span>
                    <span class="badge ok">frei</span>
                  <?php else: ?>
                    <span class="badge"><?= h((string)$occ) ?> / <?= h((string)$cap) ?></span>
                    <?php if ($full): ?>
                      <span class="badge danger">voll</span>
                    <?php else: ?>
                      <span class="badge ok"><?= h((string)$free) ?> frei</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="student-manual-select">
    <h3>Auswahlmenü</h3>
    <p class="small">Wenn du dich abmelden willst, nutze <b>Unterrichtsende</b>. Danach bist du „nicht eingebucht“ und belegst keinen Raum.</p>

    <form method="post" style="margin:10px 0">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="room_id" value="0">
      <button type="submit" class="danger">Unterrichtsende / Abmelden</button>
    </form>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Verfügbare Räume</label>
      <select name="room_id" required>
        <option value="">-- bitte wählen --</option>
        <option value="0" <?= $current ? '' : 'selected' ?>>Unterrichtsende / nicht eingebucht</option>
        <?php foreach ($rooms as $r):
          $cap = $r['capacity'];
          $occ = (int)$r['occupied'];
          $unlimited = ($cap === null || $cap === '' || (int)$cap === 0);
          $full = (!$unlimited && $occ >= (int)$cap);
          $isCurrent = $current && (int)$current['id'] === (int)$r['id'];
          $isClassRoom = (int)($r['is_class_room'] ?? 0) === 1;
          $label = $r['name'];
          if ($isClassRoom) $label .= " – Klassenraum";
          if (!empty($r['group_name'])) $label .= " – " . room_group_type_label((string)($r['group_type'] ?? '')) . ": " . (string)$r['group_name'];
          if ($unlimited) {
            $label .= " ({$occ}/∞)";
          } else {
            $label .= " ({$occ}/{$cap})";
            if ($full) $label .= " – voll";
          }
          $disabled = ($full && !$isCurrent) ? 'disabled' : '';
        ?>
          <option value="<?= (int)$r['id'] ?>" <?= $isCurrent ? 'selected' : '' ?> <?= $disabled ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
    </form>

    <?php if (!empty($_SESSION['via_kiosk'])): ?>
      <form method="post" action="<?= h(url('/logout.php')) ?>" class="small">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="to" value="kiosk">
        <span>Du bist über Kiosk eingeloggt: </span><button type="submit" class="link-button">Abmelden</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<script>
(function(){
  const table = document.getElementById('roomStatusTable');
  const select = document.querySelector('select[name="room_id"]');
  const form = select ? select.closest('form') : null;
  if (!table || !select || !form) return;

  table.addEventListener('click', function(e){
    const room = e.target.closest('.room-row');
    if (!room) return;
    const isFull = room.getAttribute('data-full') === '1';
    if (isFull) return;
    const roomId = room.getAttribute('data-room-id');
    if (!roomId) return;

    select.value = roomId;
    form.submit();
  });

  // Keyboard accessibility (Enter/Space)
  table.querySelectorAll('.room-row').forEach(room => {
    if (room.getAttribute('data-full') === '1') return;
    room.tabIndex = 0;
    room.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        room.click();
      }
    });
  });
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
