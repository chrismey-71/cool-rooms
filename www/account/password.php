<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';

$u = require_login();
$pdo = db();

// force-change banner
$must = (int)($u['must_change_password'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $current = (string)($_POST['current_password'] ?? '');
  $p1 = (string)($_POST['password'] ?? '');
  $p2 = (string)($_POST['password2'] ?? '');

  if (mb_strlen($p1, '8bit') < 8) {
    flash_set('error', 'Neues Passwort muss mindestens 8 Zeichen haben.');
    redirect(url('/account/password.php'));
  }
  if ($p1 !== $p2) {
    flash_set('error', 'Die neuen Passwörter stimmen nicht überein.');
    redirect(url('/account/password.php'));
  }

  // verify current password
  $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
  $stmt->execute([(int)$u['id']]);
  $hash = (string)($stmt->fetchColumn() ?: '');

  if (!$hash || !password_verify($current, $hash)) {
    flash_set('error', 'Aktuelles Passwort ist falsch.');
    redirect(url('/account/password.php'));
  }

  $newHash = password_hash($p1, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?");
  $stmt->execute([$newHash, (int)$u['id']]);

  // Audit event
  emit_event('password_change', [
    'actor_id' => (int)$u['id'],
    'actor_role' => (string)($u['role'] ?? ''),
    'actor_username' => (string)$u['username'],
    'actor_first_name' => (string)$u['first_name'],
    'actor_last_name' => (string)$u['last_name'],
    'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'target_user_id' => (int)$u['id'],
    'target_username' => (string)$u['username'],
    'target_first_name' => (string)$u['first_name'],
    'target_last_name' => (string)$u['last_name'],
    'target_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'must_change_before' => $must,
  ]);

  flash_set('success', 'Passwort wurde geändert.');
  redirect(url('/dashboard.php'));
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Passwort ändern</h2>
  <?php if ($must): ?>
    <div class="flash error">Du musst dein Passwort ändern, bevor du weitermachen kannst.</div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <label>Aktuelles Passwort</label>
    <input type="password" name="current_password" required autocomplete="current-password">

    <label>Neues Passwort (mind. 8 Zeichen)</label>
    <input type="password" name="password" required minlength="8" autocomplete="new-password">

    <label>Neues Passwort wiederholen</label>
    <input type="password" name="password2" required minlength="8" autocomplete="new-password">

    <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
  </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
