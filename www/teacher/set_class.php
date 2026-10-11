<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';

$u = require_teacher();
$pdo = db();

$classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name IS NOT NULL AND class_name<>'' ORDER BY class_name")->fetchAll();

$stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=?");
$stmt->execute([(int)$u['id']]);
$current = $stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $class = trim((string)($_POST['class_name'] ?? ''));
  if ($class === '') {
    $stmt = $pdo->prepare("DELETE FROM teacher_responsibility WHERE teacher_id=?");
    $stmt->execute([(int)$u['id']]);

    emit_event('teacher_responsible_class_change', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'teacher',
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'target_user_id' => (int)$u['id'],
      'target_username' => (string)$u['username'],
      'target_first_name' => (string)$u['first_name'],
      'target_last_name' => (string)$u['last_name'],
      'target_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'old_class' => $current,
      'new_class' => '',
    ]);

    flash_set('success', 'Es ist keine verantwortliche Klasse gewählt.');
    redirect(url('/teacher/home.php'));
  }

  $stmt = $pdo->prepare("
    INSERT INTO teacher_responsibility (teacher_id, class_name)
    VALUES (?,?)
    ON DUPLICATE KEY UPDATE class_name=VALUES(class_name)
  ");
  $stmt->execute([(int)$u['id'], $class]);

  emit_event('teacher_responsible_class_change', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'teacher',
    'actor_username' => (string)$u['username'],
    'actor_first_name' => (string)$u['first_name'],
    'actor_last_name' => (string)$u['last_name'],
    'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'target_user_id' => (int)$u['id'],
    'target_username' => (string)$u['username'],
    'target_first_name' => (string)$u['first_name'],
    'target_last_name' => (string)$u['last_name'],
    'target_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'old_class' => $current,
    'new_class' => $class,
  ]);

  flash_set('success', "Verantwortliche Klasse gesetzt: $class");
  redirect(url('/teacher/view_class.php'));
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Verantwortliche Klasse wählen</h2>
  <?php if ($current): ?>
    <p>Aktuell: <span class="badge"><?= h($current) ?></span></p>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Klasse</label>
    <select name="class_name">
      <option value="">-- keine Klasse gewählt --</option>
      <?php foreach ($classes as $c): $cn = $c['class_name']; ?>
        <option value="<?= h($cn) ?>" <?= $current===$cn ? 'selected' : '' ?>><?= h($cn) ?></option>
      <?php endforeach; ?>
    </select>
    <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
  </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
