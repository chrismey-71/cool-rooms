<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_groups.php';

$u = require_admin();
$pdo = db();

function ensure_class_settings_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS class_settings (
      class_name VARCHAR(80) PRIMARY KEY,
      home_room_id INT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_cs_room FOREIGN KEY (home_room_id) REFERENCES rooms(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

function class_name_exists(PDO $pdo, string $className): bool {
  $stmt = $pdo->prepare("
    SELECT 1
    FROM (
      SELECT class_name FROM users WHERE role='student' AND class_name IS NOT NULL AND class_name <> ''
      UNION
      SELECT class_name FROM teacher_responsibility
      UNION
      SELECT class_name FROM kiosk_class_codes
      UNION
      SELECT class_name FROM class_settings
    ) AS classes
    WHERE class_name = ?
    LIMIT 1
  ");
  $stmt->execute([$className]);
  return (bool)$stmt->fetchColumn();
}

ensure_class_settings_table($pdo);
ensure_room_group_schema($pdo);

$rooms = $pdo->query("
  SELECT r.id, r.name
  FROM rooms r
  LEFT JOIN room_group_type_settings rgt ON rgt.group_type = r.group_type
  LEFT JOIN room_group_settings rg ON rg.group_type = r.group_type AND rg.group_name = r.group_name
  WHERE r.is_active = 1 AND (r.is_teacher_only = 0 OR r.is_teacher_only IS NULL)
  ORDER BY COALESCE(rgt.sort_order, 9999), COALESCE(rg.sort_order, 9999), r.name
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $action = (string)($_POST['action'] ?? 'save');
  $oldClass = trim((string)($_POST['old_class_name'] ?? ''));
  $newClass = trim((string)($_POST['class_name'] ?? ''));
  $homeRoomId = (int)($_POST['home_room_id'] ?? 0);

  if ($oldClass === '') {
    flash_set('error', 'Klasse ist ungültig.');
    redirect(url('/admin/classes.php'));
  }

  $stmt = $pdo->prepare("
    SELECT cs.home_room_id, r.name AS home_room_name
    FROM class_settings cs
    LEFT JOIN rooms r ON r.id = cs.home_room_id
    WHERE cs.class_name = ?
    LIMIT 1
  ");
  $stmt->execute([$oldClass]);
  $oldSettings = $stmt->fetch() ?: null;

  $oldHomeRoomId = (int)($oldSettings['home_room_id'] ?? 0);
  $oldHomeRoomName = (string)($oldSettings['home_room_name'] ?? '');

  if ($action === 'delete') {
    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("DELETE FROM users WHERE role='student' AND class_name=?");
      $stmt->execute([$oldClass]);
      $deletedStudents = $stmt->rowCount();

      $stmt = $pdo->prepare("DELETE FROM teacher_responsibility WHERE class_name=?");
      $stmt->execute([$oldClass]);
      $affectedTeacherResponsibilities = $stmt->rowCount();

      $stmt = $pdo->prepare("DELETE FROM kiosk_class_codes WHERE class_name=?");
      $stmt->execute([$oldClass]);
      $deletedKioskCodes = $stmt->rowCount();

      $stmt = $pdo->prepare("DELETE FROM class_settings WHERE class_name=?");
      $stmt->execute([$oldClass]);

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }

    emit_event('admin_class_deleted', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'admin',
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'class_name' => $oldClass,
      'old_home_room_id' => $oldHomeRoomId,
      'old_home_room_name' => $oldHomeRoomName,
      'deleted_students' => $deletedStudents,
      'removed_teacher_responsibilities' => $affectedTeacherResponsibilities,
      'deleted_kiosk_codes' => $deletedKioskCodes,
    ]);

    flash_set(
      'success',
      "Klasse $oldClass gelöscht. $deletedStudents zugehörige Schüler:innen wurden ebenfalls gelöscht."
    );
    redirect(url('/admin/classes.php'));
  }

  if ($newClass === '') {
    flash_set('error', 'Klassenname darf nicht leer sein.');
    redirect(url('/admin/classes.php'));
  }

  $newHomeRoomName = '';
  $newHomeRoomDbValue = null;
  if ($homeRoomId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, name
      FROM rooms
      WHERE id = ? AND is_active = 1 AND (is_teacher_only = 0 OR is_teacher_only IS NULL)
      LIMIT 1
    ");
    $stmt->execute([$homeRoomId]);
    $room = $stmt->fetch() ?: null;
    if (!$room) {
      flash_set('error', 'Der gewählte Klassenraum ist ungültig.');
      redirect(url('/admin/classes.php'));
    }
    $newHomeRoomDbValue = (int)$room['id'];
    $newHomeRoomName = (string)$room['name'];
  }

  if ($newClass !== $oldClass && class_name_exists($pdo, $newClass)) {
    flash_set('error', "Die Klasse $newClass existiert bereits.");
    redirect(url('/admin/classes.php'));
  }

  if ($newClass === $oldClass && $oldHomeRoomId === (int)$homeRoomId) {
    flash_set('success', 'Keine Änderung erforderlich.');
    redirect(url('/admin/classes.php'));
  }

  try {
    $pdo->beginTransaction();

    if ($newClass !== $oldClass) {
      $stmt = $pdo->prepare("UPDATE users SET class_name=? WHERE role='student' AND class_name=?");
      $stmt->execute([$newClass, $oldClass]);

      $stmt = $pdo->prepare("UPDATE teacher_responsibility SET class_name=? WHERE class_name=?");
      $stmt->execute([$newClass, $oldClass]);

      $stmt = $pdo->prepare("UPDATE kiosk_class_codes SET class_name=? WHERE class_name=?");
      $stmt->execute([$newClass, $oldClass]);

      $stmt = $pdo->prepare("UPDATE class_settings SET class_name=? WHERE class_name=?");
      $stmt->execute([$newClass, $oldClass]);
    }

    $stmt = $pdo->prepare("
      INSERT INTO class_settings (class_name, home_room_id)
      VALUES (?,?)
      ON DUPLICATE KEY UPDATE home_room_id=VALUES(home_room_id), updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->execute([$newClass, $newHomeRoomDbValue]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  emit_event('admin_class_updated', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'admin',
    'actor_username' => (string)$u['username'],
    'actor_first_name' => (string)$u['first_name'],
    'actor_last_name' => (string)$u['last_name'],
    'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'old_class_name' => $oldClass,
    'class_name' => $newClass,
    'old_home_room_id' => $oldHomeRoomId,
    'old_home_room_name' => $oldHomeRoomName,
    'new_home_room_id' => (int)$homeRoomId,
    'new_home_room_name' => $newHomeRoomName,
  ]);

  $parts = [];
  if ($newClass !== $oldClass) {
    $parts[] = "Klasse umbenannt: $oldClass → $newClass";
  }
  $parts[] = $homeRoomId > 0
    ? "Klassenraum gesetzt: $newHomeRoomName"
    : 'Klassenraum entfernt';

  flash_set('success', implode(' · ', $parts));
  redirect(url('/admin/classes.php'));
}

$classes = $pdo->query("
  SELECT c.class_name,
         COALESCE(cs.home_room_id, 0) AS home_room_id,
         r.name AS home_room_name,
         COALESCE(sc.student_count, 0) AS student_count,
         COALESCE(tc.teacher_count, 0) AS teacher_count
  FROM (
    SELECT class_name FROM users WHERE role='student' AND class_name IS NOT NULL AND class_name <> ''
    UNION
    SELECT class_name FROM teacher_responsibility
    UNION
    SELECT class_name FROM kiosk_class_codes
    UNION
    SELECT class_name FROM class_settings
  ) AS c
  LEFT JOIN class_settings cs ON cs.class_name = c.class_name
  LEFT JOIN rooms r ON r.id = cs.home_room_id
  LEFT JOIN (
    SELECT class_name, COUNT(*) AS student_count
    FROM users
    WHERE role='student' AND class_name IS NOT NULL AND class_name <> ''
    GROUP BY class_name
  ) AS sc ON sc.class_name = c.class_name
  LEFT JOIN (
    SELECT class_name, COUNT(*) AS teacher_count
    FROM teacher_responsibility
    GROUP BY class_name
  ) AS tc ON tc.class_name = c.class_name
  ORDER BY c.class_name
")->fetchAll();

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Klassen verwalten</h2>
  <p class="small">Hier kannst du Klassen zum Schuljahreswechsel umbenennen, löschen und jeder Klasse einen festen Klassenraum zuweisen. Beim Umbenennen bleiben Schüler:innen, verantwortliche Klassen und Klassen-Kiosk-Einträge erhalten und werden auf die neue Bezeichnung umgestellt. Beim Löschen werden auch die direkt zugehörigen Schüler:innen gelöscht.</p>

  <?php if (!$classes): ?>
    <p>Es sind aktuell keine Klassen vorhanden.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Aktuelle Klasse</th><th>Schüler:innen</th><th>Lehrkräfte</th><th>Neuer Name</th><th>Klassenraum</th><th>Aktionen</th></tr></thead>
      <tbody>
        <?php foreach ($classes as $row): ?>
          <tr>
            <td>
              <strong><?= h((string)$row['class_name']) ?></strong>
              <?php if (!empty($row['home_room_name'])): ?>
                <div class="small">Aktueller Klassenraum: <?= h((string)$row['home_room_name']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= h((string)$row['student_count']) ?></td>
            <td><?= h((string)$row['teacher_count']) ?></td>
            <td colspan="3">
              <form method="post" class="row" style="align-items:end;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="old_class_name" value="<?= h((string)$row['class_name']) ?>">
                <div style="min-width:180px;">
                  <label>Neuer Klassenname</label>
                  <input name="class_name" value="<?= h((string)$row['class_name']) ?>" required>
                </div>
                <div style="min-width:220px;">
                  <label>Klassenraum</label>
                  <select name="home_room_id">
                    <option value="0">-- kein Klassenraum --</option>
                    <?php foreach ($rooms as $room): ?>
                      <option value="<?= (int)$room['id'] ?>" <?= (int)$row['home_room_id'] === (int)$room['id'] ? 'selected' : '' ?>>
                        <?= h((string)$room['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div style="min-width:180px;">
                  <button type="submit" name="action" value="save">Speichern</button>
                  <button
                    type="submit"
                    name="action"
                    value="delete"
                    class="danger"
                    formnovalidate
                    onclick="return confirm('Diese Klasse und alle zugehörigen Schüler:innen wirklich unwiderruflich löschen?');"
                  >Löschen</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
