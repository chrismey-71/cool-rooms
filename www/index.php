<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/csrf.php';

if (auth_user()) redirect(url('/dashboard.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $username = trim($_POST['username'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');

  if (login_attempt($username, $pass)) {
    redirect(url('/dashboard.php'));
  }
  if (!empty($_SESSION['login_retry_after'])) {
    $minutes = max(1, (int)ceil((int)$_SESSION['login_retry_after'] / 60));
    flash_set('error', 'Zu viele fehlgeschlagene Versuche. Bitte in etwa ' . $minutes . ' Minute(n) erneut versuchen.');
  } else {
    flash_set('error', 'Login fehlgeschlagen.');
  }
  redirect(url('/index.php'));
}

require __DIR__ . '/partials/header.php';
?>
<div class="card">
  <h2>Login</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="row">
      <div>
        <label>Username</label>
        <input name="username" type="text" required autocomplete="username">
      </div>
      <div>
        <label>Passwort</label>
        <input name="password" type="password" required autocomplete="current-password">
      </div>
    </div>
    <p style="margin-top:12px;"><button type="submit">Einloggen</button></p>
  </form>

  <p class="small">Schüler:innen am COOL-Tag? <a href="<?= h(url('/kiosk.php')) ?>">Zum Kiosk-Login</a></p>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
