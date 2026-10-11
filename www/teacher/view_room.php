<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/room_groups.php';

$u = require_teacher();
$pdo = db();

ensure_room_group_schema($pdo);

$rooms = $pdo->query("
  SELECT r.id, r.name
  FROM rooms r
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = r.group_type
  LEFT JOIN room_group_settings rg ON rg.group_type = r.group_type AND rg.group_name = r.group_name
  WHERE r.is_active=1
  ORDER BY COALESCE(rgt.sort_order, 9999), COALESCE(rg.sort_order, 9999), r.name
")->fetchAll();

$roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$students = [];
$teachers = [];
$roomName = '';

if ($roomId > 0) {
  $stmt = $pdo->prepare("SELECT name FROM rooms WHERE id=?");
  $stmt->execute([$roomId]);
  $roomName = (string)($stmt->fetchColumn() ?: '');

  $stmt = $pdo->prepare("
    SELECT s.first_name, s.last_name, s.class_name, s.username, s.is_active, ra.updated_at
    FROM room_assignments ra
    JOIN users s ON s.id = ra.student_id
    WHERE ra.room_id=?
    ORDER BY s.class_name, s.last_name, s.first_name
  ");
  $stmt->execute([$roomId]);
  $students = $stmt->fetchAll();


  $stmt = $pdo->prepare("
    SELECT t.first_name, t.last_name, t.username, t.is_active, tl.updated_at
    FROM teacher_locations tl
    JOIN users t ON t.id = tl.teacher_id
    WHERE tl.room_id=?
    ORDER BY t.last_name, t.first_name
  ");
  $stmt->execute([$roomId]);
  $teachers = $stmt->fetchAll();

}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Raum live anzeigen <?= $roomId>0 ? '<span class="badge ok">Live</span>' : '' ?></h2>

  <form method="get">
    <label>Raum</label>
    <select name="room_id" onchange="this.form.submit()">
      <option value="0">-- wählen --</option>
      <?php foreach ($rooms as $r): ?>
        <option value="<?= (int)$r['id'] ?>" <?= $roomId===(int)$r['id'] ? 'selected' : '' ?>>
          <?= h($r['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($roomId > 0): ?>
    <div class="card">
      <h3><?= h($roomName) ?></h3>
      <h4>Lehrkräfte in diesem Raum</h4>
      <table class="table">
        <thead><tr><th>Name</th><th>Username</th><th>Aktiv</th><th>Stand</th></tr></thead>
        <tbody>
          <?php foreach ($teachers as $t): ?>
            <tr>
              <td><?= h($t['last_name'] . ', ' . $t['first_name']) ?></td>
              <td><?= h($t['username']) ?></td>
              <td>
                <?php if ((int)($t['is_active'] ?? 1) === 1): ?>
                  <span class="badge ok">aktiv</span>
                <?php else: ?>
                  <span class="badge warn">inaktiv</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= h($t['updated_at'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$teachers): ?>
            <tr><td colspan="4" class="small">Keine Lehrkräfte eingebucht.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <h4>Schüler:innen in diesem Raum</h4>
      <table class="table">
        <thead><tr><th>Nr.</th><th>Klasse</th><th>Name</th><th>Username</th><th>Aktiv</th><th>Stand</th></tr></thead>
        <tbody>
          <?php foreach ($students as $i => $s): ?>
            <tr>
              <td data-label="Nr."><?= $i + 1 ?></td>
              <td><?= h($s['class_name'] ?? '') ?></td>
              <td><?= h($s['last_name'] . ', ' . $s['first_name']) ?></td>
              <td><?= h($s['username']) ?></td>
              <td>
                <?php if ((int)($s['is_active'] ?? 1) === 1): ?>
                  <span class="badge ok">aktiv</span>
                <?php else: ?>
                  <span class="badge warn">inaktiv</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= h($s['updated_at'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$students): ?>
            <tr><td colspan="6" class="small">Keine Einbuchungen in diesem Raum.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <p class="small">Hinweis: Raumansicht aktualisiert sich „sanft“ (kurzer Reload), wenn sich Einbuchungen ändern.</p>
    </div>

    <script>
    (() => {
      const streamUrl = <?= json_encode(url('/stream.php?scope=room&room_id=' . (int)$roomId)) ?>;
      const es = new EventSource(streamUrl);
      let t = null;
      const scheduleReload = () => {
        if (t) return;
        t = setTimeout(() => location.reload(), 700);
      };
      es.addEventListener('assignment', scheduleReload);
      es.addEventListener('teacher_class_home_room_booking', scheduleReload);
      es.addEventListener('teacher_student_active_change', scheduleReload);
      es.addEventListener('teacher_location', scheduleReload);
      es.addEventListener('reset', () => location.reload());
      window.addEventListener('pagehide', () => es.close(), { once: true });
    })();
    </script>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
