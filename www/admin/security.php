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
  $loginPerMinute = filter_input(INPUT_POST, 'auth_login_per_minute', FILTER_VALIDATE_INT);
  $kioskPerMinute = filter_input(INPUT_POST, 'auth_kiosk_per_minute', FILTER_VALIDATE_INT);

  if ($attempts === false || $attempts === null || $attempts < 3 || $attempts > 20) {
    flash_set('error', 'Die Anzahl der Fehlversuche muss zwischen 3 und 20 liegen.');
    redirect(url('/admin/security.php'));
  }
  if ($minutes === false || $minutes === null || $minutes < 1 || $minutes > 30) {
    flash_set('error', 'Die Sperrdauer muss zwischen 1 und 30 Minuten liegen.');
    redirect(url('/admin/security.php'));
  }
  if (!$loginPerMinute || $loginPerMinute < 100 || $loginPerMinute > 5000
    || !$kioskPerMinute || $kioskPerMinute < 30 || $kioskPerMinute > 1000) {
    flash_set('error', 'Kurzzeitlimits: Login 100–5000, Kiosk 30–1000 Anfragen pro Minute.');
    redirect(url('/admin/security.php'));
  }

  $stmt = $pdo->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $stmt->execute(['auth_max_attempts', (string)$attempts]);
  $stmt->execute(['auth_lock_minutes', (string)$minutes]);
  $stmt->execute(['auth_login_per_minute', (string)$loginPerMinute]);
  $stmt->execute(['auth_kiosk_per_minute', (string)$kioskPerMinute]);
  flash_set('success', 'Sicherheitseinstellungen gespeichert.');
  redirect(url('/admin/security.php'));
}

$limits = security_login_limits($pdo);
require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Login-Schutz</h2>
  <p>
    Normale Anmeldungen werden pro Benutzerkonto begrenzt, unabhängig von der IP-Adresse.
    Falsche Kiosk-PINs sperren nur weitere Codeprüfungen im betreffenden Browser.
    Die Fehlversuche verschiedener Schüler:innen im Schulnetz werden nicht zu einer gemeinsamen minutenlangen Sperre zusammengezählt.
  </p>
  <p class="small">
    Nach Ablauf der Sperrdauer steht die volle Versuchszahl wieder zur Verfügung.
    Erfolgreiche Anmeldungen setzen nur den zugehörigen Konto- beziehungsweise Browserzähler zurück.
    Kennwörter, Kiosk-PINs und bereits freigeschaltete Kiosk-Zugänge bleiben unverändert.
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
    <h3>Zusätzlicher Kurzzeitschutz für gemeinsame Schulnetze</h3>
    <p class="small">Diese großzügigen Limits zählen alle Prüfungen je Server-IP, auch erfolgreiche.
      Nach Überschreiten werden weitere Prüfungen bis zum Ende des laufenden 60-Sekunden-Fensters abgewiesen, höchstens für 60 Sekunden.
      Weitere Anfragen verlängern das Fenster nicht. Konto- und Kiosk-Prüfungen haben getrennte Limits.
      Auch andere Geräte derselben Schul-IP können dann kurz warten müssen. Bei großen Schulen entsprechend erhöhen.</p>
    <div class="row">
      <div>
        <label>Normale Anmeldungen je IP und Minute</label>
        <input type="number" name="auth_login_per_minute" min="100" max="5000" value="<?= (int)$limits['login_per_minute'] ?>" required>
        <div class="small">Standard: 600. Erlaubt: 100–5000.</div>
      </div>
      <div>
        <label>Kiosk-Codeprüfungen je IP und Minute</label>
        <input type="number" name="auth_kiosk_per_minute" min="30" max="1000" value="<?= (int)$limits['kiosk_per_minute'] ?>" required>
        <div class="small">Standard: 120. Erlaubt: 30–1000.</div>
      </div>
    </div>
    <p class="small">Die Kiosk-Browserkennung ist ein signiertes Sitzungscookie und keine Zugangsberechtigung.
      Neue Browser oder gelöschte Cookies können die Browserbegrenzung umgehen; dagegen hilft ergänzend der Kurzzeitschutz.
      Hinter einem Reverse Proxy muss der Server die tatsächliche Client-IP korrekt bereitstellen.</p>
    <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
  </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
