<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/security.php';

$u = require_admin();
$pdo = db();
security_ensure_throttle_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $attempts = filter_input(INPUT_POST, 'auth_max_attempts', FILTER_VALIDATE_INT);
  $minutes = filter_input(INPUT_POST, 'auth_lock_minutes', FILTER_VALIDATE_INT);

  if ($attempts === false || $attempts === null || $attempts < 3 || $attempts > 20) {
    flash_set('error', 'Die Anzahl der Fehlversuche muss zwischen 3 und 20 liegen.');
    redirect(url('/admin/security.php'));
  }
  if ($minutes === false || $minutes === null || $minutes < 1 || $minutes > 30) {
    flash_set('error', 'Die Sperrdauer muss zwischen 1 und 30 Minuten liegen.');
    redirect(url('/admin/security.php'));
  }

  $stmt = $pdo->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $stmt->execute(['auth_max_attempts', (string)$attempts]);
  $stmt->execute(['auth_lock_minutes', (string)$minutes]);
  flash_set('success', 'Sicherheitseinstellungen gespeichert.');
  redirect(url('/admin/security.php'));
}

$limits = security_login_limits($pdo);
require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Login-Schutz</h2>
  <p>
    Fehlgeschlagene Anmeldungen werden pro Benutzerkonto und Zugriffsquelle gezählt.
    Die Begrenzung gilt auch für falsche Kiosk-PINs und erschwert automatisiertes Ausprobieren.
  </p>
  <p class="small">
    Nach Ablauf der Sperrdauer kann dasselbe Konto beziehungsweise Gerät automatisch wieder verwendet werden.
    Kennwörter und Kiosk-PINs werden durch die Sperre nicht geändert oder deaktiviert.
  </p>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="row">
      <div>
        <label>Fehlversuche bis zur Sperre</label>
        <input type="number" name="auth_max_attempts" min="3" max="20" value="<?= (int)$limits['attempts'] ?>" required>
        <div class="small">Erlaubt sind 3 bis 20 Versuche.</div>
      </div>
      <div>
        <label>Sperrdauer in Minuten</label>
        <input type="number" name="auth_lock_minutes" min="1" max="30" value="<?= (int)$limits['lock_minutes'] ?>" required>
        <div class="small">Erlaubt sind 1 bis 30 Minuten.</div>
      </div>
    </div>
    <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
  </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
