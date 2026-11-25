<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/room_assignments.php';
require_once __DIR__ . '/../lib/class_group_view.php';

$u = require_teacher();
$pdo = db();
class_groups_schema($pdo);

$stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=?");
$stmt->execute([(int)$u['id']]);
$class = $stmt->fetchColumn();
$classHomeRoom = null;
$manualGroup=is_string($_GET['group']??null)?$_GET['group']:null;
try{$view=class_group_view($pdo,$u,(string)$class,$manualGroup);}
catch(InvalidArgumentException $e){flash_set('error',$e->getMessage());redirect(url('/teacher/view_class.php'));}
[$groupSql,$groupParams]=class_groups_condition($view['group_ids']);
$viewUrl=url('/teacher/view_class.php').($view['filter_query']!==''?'?group='.urlencode($view['filter_query']):'');

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

  if(!hash_equals($view['token'],(string)($_POST['group_scope']??''))){flash_set('error','Die angezeigte Klasse oder der Stundenplanfilter hat sich geändert. Bitte die Auswahl erneut prüfen.');redirect($viewUrl);}

  $action = (string)($_POST['action'] ?? 'reset_assignments');
  if(!in_array($action,['book_home_room','mark_inactive','mark_active','reset_assignments'],true)){flash_set('error','Unbekannte Aktion.');redirect($viewUrl);}

  if ($action === 'book_home_room') {
    if (!$classHomeRoom) {
      flash_set('error', 'Für diese Klasse ist kein aktiver Klassenraum hinterlegt.');
      redirect($viewUrl);
    }

    $roomId = (int)$classHomeRoom['id'];
    $roomName = (string)$classHomeRoom['name'];

    $stmt = $pdo->prepare("SELECT s.id FROM users s WHERE s.role='student' AND s.class_name=? AND s.is_active=1 $groupSql ORDER BY s.id");
    $stmt->execute(array_merge([$class],$groupParams));
    $studentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    try {
      $affectedStudents = assign_students_to_room($pdo, $studentIds, $roomId);
    } catch (RoomAssignmentCapacityException|InvalidArgumentException $e) {
      flash_set('error', $e->getMessage());
      redirect($viewUrl);
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
      'group_ids' => $view['group_ids'],
      'group_selection' => $view['label'],
    ]);

    flash_set('success', "$affectedStudents anwesende Schüler:innen der angezeigten Auswahl wurden in den Klassenraum $roomName gebucht.");
    redirect($viewUrl);
  }

  if ($action === 'mark_inactive' || $action === 'mark_active') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $newActive = $action === 'mark_active' ? 1 : 0;

    $stmt = $pdo->prepare("
      SELECT id, first_name, last_name, username, is_active
      FROM users s
      WHERE s.id=? AND s.role='student' AND s.class_name=? $groupSql
      LIMIT 1
    ");
    $stmt->execute(array_merge([$studentId,$class],$groupParams));
    $student = $stmt->fetch() ?: null;

    if (!$student) {
      flash_set('error', 'Schüler:in gehört nicht zur angezeigten Klasse oder Gruppe.');
      redirect($viewUrl);
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
    redirect($viewUrl);
  }

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM room_assignments ra
    JOIN users s ON s.id = ra.student_id
    WHERE s.role='student' AND s.class_name=? $groupSql
  ");
  $stmt->execute(array_merge([$class],$groupParams));
  $deletedAssignments = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare("
    DELETE FROM room_assignments
    WHERE student_id IN (
      SELECT s.id
      FROM users s
      WHERE s.role='student' AND s.class_name=? $groupSql
    )
  ");
  $stmt->execute(array_merge([$class],$groupParams));

  emit_event('teacher_class_assignments_reset', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'teacher',
    'actor_username' => (string)$u['username'],
    'actor_first_name' => (string)$u['first_name'],
    'actor_last_name' => (string)$u['last_name'],
    'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'class_name' => (string)$class,
    'deleted_student_assignments' => $deletedAssignments,
    'group_ids' => $view['group_ids'],
    'group_selection' => $view['label'],
  ]);

  emit_event('reset', [
    'by' => (int)$u['id'],
    'scope' => 'class',
    'class_name' => (string)$class,
  ]);

  $msg = $deletedAssignments > 0
    ? "Raumzuordnungen für $class · {$view['label']} gelöscht. $deletedAssignments Einbuchungen entfernt."
    : "Für $class · {$view['label']} gab es keine aktiven Raumzuordnungen mehr.";
  flash_set('success', $msg);
  redirect($viewUrl);
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
  SELECT s.id, s.first_name, s.last_name, s.is_active,
         r.name AS room_name,
         ra.updated_at
  FROM users s
  LEFT JOIN room_assignments ra ON ra.student_id = s.id
  LEFT JOIN rooms r ON r.id = ra.room_id
  WHERE s.role='student' AND s.class_name=? $groupSql
  ORDER BY s.last_name, s.first_name
");
$stmt->execute(array_merge([$class],$groupParams));
$students = $stmt->fetchAll();
require __DIR__.'/../partials/class_roster.php';
require __DIR__.'/../partials/footer.php';
