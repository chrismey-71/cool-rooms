<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_assignments.php';

$u = require_teacher();
$pdo = db();

$stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=?");
$stmt->execute([(int)$u['id']]);
$class = $stmt->fetchColumn();
$classHomeRoom = null;

if ($class) {
  try {
    $stmt = $pdo->prepare("
      SELECT r.id, r.name
      FROM class_settings cs
      JOIN rooms r ON r.id = cs.home_room_id
      WHERE cs.class_name=? AND r.is_active=1 AND (r.is_teacher_only=0 OR r.is_teacher_only IS NULL)
      LIMIT 1
    ");
    $stmt->execute([(string)$class]);
    $classHomeRoom = $stmt->fetch() ?: null;
  } catch (Throwable $e) {
    $classHomeRoom = null;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  if (!$class) {
    flash_set('error', 'Du hast noch keine verantwortliche Klasse gewählt.');
    redirect(url('/teacher/set_class.php'));
  }

  $action = (string)($_POST['action'] ?? 'reset_assignments');

  if ($action === 'book_home_room') {
    if (!$classHomeRoom) {
      flash_set('error', 'Für diese Klasse ist kein aktiver Klassenraum hinterlegt.');
      redirect(url('/teacher/view_class.php'));
    }

    $roomId = (int)$classHomeRoom['id'];
    $roomName = (string)$classHomeRoom['name'];

    $stmt = $pdo->prepare("SELECT id FROM users WHERE role='student' AND class_name=? AND is_active=1 ORDER BY id");
    $stmt->execute([$class]);
    $studentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    try {
      $affectedStudents = assign_students_to_room($pdo, $studentIds, $roomId);
    } catch (RoomAssignmentCapacityException|InvalidArgumentException $e) {
      flash_set('error', $e->getMessage());
      redirect(url('/teacher/view_class.php'));
    }

    emit_event('teacher_class_home_room_booking', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'teacher',
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'class_name' => (string)$class,
      'room_id' => $roomId,
      'room_name' => $roomName,
      'affected_students' => $affectedStudents,
    ]);

    flash_set('success', "$affectedStudents anwesende Schüler:innen wurden in den Klassenraum $roomName gebucht.");
    redirect(url('/teacher/view_class.php'));
  }

  if ($action === 'mark_inactive' || $action === 'mark_active') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $newActive = $action === 'mark_active' ? 1 : 0;

    $stmt = $pdo->prepare("
      SELECT id, first_name, last_name, username, is_active
      FROM users
      WHERE id=? AND role='student' AND class_name=?
      LIMIT 1
    ");
    $stmt->execute([$studentId, $class]);
    $student = $stmt->fetch() ?: null;

    if (!$student) {
      flash_set('error', 'Schüler:in gehört nicht zu deiner verantwortlichen Klasse.');
      redirect(url('/teacher/view_class.php'));
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active=? WHERE id=? AND role='student' AND class_name=?");
    $stmt->execute([$newActive, $studentId, $class]);

    if ($newActive === 0) {
      $stmt = $pdo->prepare("DELETE FROM room_assignments WHERE student_id=?");
      $stmt->execute([$studentId]);
    }

    emit_event('teacher_student_active_change', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'teacher',
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'class_name' => (string)$class,
      'student_id' => $studentId,
      'target_username' => (string)$student['username'],
      'target_first_name' => (string)$student['first_name'],
      'target_last_name' => (string)$student['last_name'],
      'target_name' => trim((string)$student['last_name'] . ', ' . (string)$student['first_name']),
      'old_active' => (int)$student['is_active'],
      'new_active' => $newActive,
    ]);

    $name = trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
    flash_set('success', $newActive === 1 ? "$name ist wieder anwesend." : "$name wurde auf nicht anwesend gesetzt.");
    redirect(url('/teacher/view_class.php'));
  }

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM room_assignments ra
    JOIN users s ON s.id = ra.student_id
    WHERE s.role='student' AND s.class_name=?
  ");
  $stmt->execute([$class]);
  $deletedAssignments = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    DELETE FROM room_assignments
    WHERE student_id IN (
      SELECT id
      FROM users
      WHERE role='student' AND class_name=?
    )
  ");
  $stmt->execute([$class]);

  emit_event('teacher_class_assignments_reset', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'teacher',
    'actor_username' => (string)$u['username'],
    'actor_first_name' => (string)$u['first_name'],
    'actor_last_name' => (string)$u['last_name'],
    'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'class_name' => (string)$class,
    'deleted_student_assignments' => $deletedAssignments,
  ]);

  emit_event('reset', [
    'by' => (int)$u['id'],
    'scope' => 'class',
    'class_name' => (string)$class,
  ]);

  $msg = $deletedAssignments > 0
    ? "Raumzuordnungen für Klasse $class gelöscht. $deletedAssignments Einbuchungen entfernt."
    : "Für Klasse $class gab es keine aktiven Raumzuordnungen mehr.";
  flash_set('success', $msg);
  redirect(url('/teacher/view_class.php'));
}

require __DIR__ . '/../partials/header.php';

if (!$class):
?>
<div class="card">
  <h2>Klasse live anzeigen</h2>
  <p>Du hast noch keine verantwortliche Klasse gewählt.</p>
  <p><a href="<?= h(url('/teacher/set_class.php')) ?>">Jetzt Klasse auswählen</a></p>
</div>
<?php
require __DIR__ . '/../partials/footer.php';
exit;
endif;

$stmt = $pdo->prepare("
  SELECT s.id, s.first_name, s.last_name, s.username, s.is_active,
         r.name AS room_name,
         ra.updated_at
  FROM users s
  LEFT JOIN room_assignments ra ON ra.student_id = s.id
  LEFT JOIN rooms r ON r.id = ra.room_id
  WHERE s.role='student' AND s.class_name=?
  ORDER BY s.last_name, s.first_name
");
$stmt->execute([$class]);
$students = $stmt->fetchAll();
?>
<div class="card">
  <h2>Klasse: <?= h($class) ?> <span class="badge ok">Live</span></h2>
  <p class="small">„nicht eingebucht“ = kein Raum gewählt. Änderungen erscheinen automatisch.</p>

  <div class="card">
    <h3>Klassenaktionen</h3>
    <p class="small">Diese Aktionen betreffen immer nur deine verantwortliche Klasse. Nicht anwesende Schüler:innen werden beim Buchen in den Klassenraum übersprungen.</p>
    <div class="row">
      <form method="post" onsubmit="return confirm('Alle anwesenden Schüler:innen dieser Klasse in den Klassenraum buchen?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="book_home_room">
        <button type="submit" <?= $classHomeRoom ? '' : 'disabled' ?>>Alle in den Klassenraum buchen</button>
        <p class="small">
          <?php if ($classHomeRoom): ?>
            Klassenraum: <?= h((string)$classHomeRoom['name']) ?>
          <?php else: ?>
            Kein Klassenraum hinterlegt.
          <?php endif; ?>
        </p>
      </form>
      <form method="post" onsubmit="return confirm('Wirklich alle Raumzuordnungen dieser Klasse löschen?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_assignments">
        <button class="danger" type="submit">Jetzt Raumzuordnungen dieser Klasse löschen</button>
      </form>
    </div>
  </div>

  <table class="table" id="class-table">
    <thead><tr><th>Nr.</th><th>Name</th><th>Username</th><th>Anwesenheit</th><th>Raum</th><th>Stand</th><th>Aktion</th></tr></thead>
    <tbody>
      <?php foreach ($students as $i => $s): ?>
        <tr data-student-id="<?= (int)$s['id'] ?>">
          <td data-label="Nr."><?= $i + 1 ?></td>
          <td data-label="Name"><?= h($s['last_name'] . ', ' . $s['first_name']) ?></td>
          <td data-label="Username"><?= h($s['username']) ?></td>
          <td data-label="Anwesenheit">
            <?php if ((int)($s['is_active'] ?? 1) === 1): ?>
              <span class="badge ok">anwesend</span>
            <?php else: ?>
              <span class="badge warn">nicht anwesend</span>
            <?php endif; ?>
          </td>
          <td class="room-cell" data-label="Raum">
            <?php if ($s['room_name']): ?>
              <span class="badge ok"><?= h($s['room_name']) ?></span>
            <?php else: ?>
              <span class="badge warn">nicht eingebucht</span>
            <?php endif; ?>
          </td>
          <td class="ts-cell small" data-label="Stand"><?= h($s['updated_at'] ?? '') ?></td>
          <td data-label="Aktion">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
              <?php if ((int)($s['is_active'] ?? 1) === 1): ?>
                <button class="small class-status-action class-status-action--inactive" type="submit" name="action" value="mark_inactive">Nicht anwesend setzen</button>
              <?php else: ?>
                <button class="small class-status-action class-status-action--active" type="submit" name="action" value="mark_active">Anwesend setzen</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
(() => {
  const CLASS_NAME = <?= json_encode($class, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const streamUrl = <?= json_encode(url('/stream.php?scope=class&class=' . urlencode($class))) ?>;

  const es = new EventSource(streamUrl);

  es.addEventListener('assignment', (ev) => {
    let p;
    try { p = JSON.parse(ev.data); } catch { return; }
    if (!p || p.class_name !== CLASS_NAME) return;

    const studentId = Number.parseInt(String(p.student_id), 10);
    if (!Number.isInteger(studentId) || studentId < 1) return;
    const tr = document.querySelector(`tr[data-student-id="${studentId}"]`);
    if (!tr) return;

    const roomCell = tr.querySelector('.room-cell');
    const tsCell = tr.querySelector('.ts-cell');

    if (roomCell) {
      roomCell.innerHTML = p.room_name
        ? `<span class="badge ok">${escapeHtml(p.room_name)}</span>`
        : `<span class="badge warn">nicht eingebucht</span>`;
    }
    if (tsCell) tsCell.textContent = p.updated_at || '';
  });

  es.addEventListener('teacher_class_home_room_booking', () => location.reload());
  es.addEventListener('teacher_student_active_change', () => location.reload());
  es.addEventListener('reset', () => location.reload());
  es.onerror = () => {}; // browser reconnects automatically
  window.addEventListener('pagehide', () => es.close(), { once: true });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
