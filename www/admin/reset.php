<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';

$u = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $cntStudents = (int)$pdo->query("SELECT COUNT(*) FROM room_assignments")->fetchColumn();
  $cntTeachers = (int)$pdo->query("SELECT COUNT(*) FROM teacher_locations")->fetchColumn();
  $cntTeacherClasses = (int)$pdo->query("SELECT COUNT(*) FROM teacher_responsibility")->fetchColumn();
  $cntAbsentStudents = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=0")->fetchColumn();

  $pdo->exec("DELETE FROM room_assignments");
  $pdo->exec("DELETE FROM teacher_locations");
  $pdo->exec("DELETE FROM teacher_responsibility");
  $pdo->exec("UPDATE users SET is_active=1 WHERE role='student'");

  emit_event('admin_reset_all_assignments', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'admin',
    'deleted_student_assignments' => $cntStudents,
    'deleted_teacher_locations' => $cntTeachers,
    'deleted_teacher_responsibilities' => $cntTeacherClasses,
    'students_set_present' => $cntAbsentStudents,
  ]);

  // Backward compatible event type (older reports)
  emit_event('reset', ['by' => (int)$u['id']]);
  flash_set('success', 'Alle Raumzuordnungen und verantwortlichen Klassen wurden gelöscht. Alle Schüler:innen sind wieder anwesend und nicht eingebucht.');
  redirect(url('/admin/reset.php'));
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Reset am Ende des COOL-Tages</h2>
  <p>Damit werden <strong>alle aktuellen Raumzuordnungen</strong> und die <strong>verantwortlichen Klassen der Lehrkräfte</strong> gelöscht. Zusätzlich werden alle Schüler:innen wieder auf <strong>anwesend</strong> gesetzt.</p>
  <form method="post" onsubmit="return confirm('Wirklich ALLE Raumzuordnungen und verantwortlichen Klassen löschen und alle Schüler:innen auf anwesend setzen?')">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="danger" type="submit">Jetzt Raumzuordnungen und Klassen zurücksetzen</button>
  </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
