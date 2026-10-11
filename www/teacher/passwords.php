<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';

$u = require_teacher();
$pdo = db();

/**
 * Generate a short numeric PIN (default 6 digits).
 */
function gen_pin(int $len = 6): string {
  $min = 0;
  $max = (10 ** $len) - 1;
  $n = random_int($min, $max);
  return str_pad((string)$n, $len, '0', STR_PAD_LEFT);
}

// Determine allowed class scope:
// - Teacher: only their responsible class
// - Admin: can choose class
$stmt = $pdo->prepare("SELECT class_name FROM teacher_responsibility WHERE teacher_id=?");
$stmt->execute([(int)$u['id']]);
$myClass = $stmt->fetchColumn() ?: null;

$isAdmin = is_admin_user($u);

$selectedClass = '';
if ($isAdmin) {
  $selectedClass = trim((string)($_GET['class'] ?? ''));
} else {
  $selectedClass = (string)$myClass;
}

if (!$isAdmin && !$selectedClass) {
  require __DIR__ . '/../partials/header.php';
  echo '<div class="card"><h2>Schüler-PINs</h2><p>Du hast noch keine verantwortliche Klasse gewählt.</p><p><a href="'.h(url('/teacher/set_class.php')).'">Jetzt Klasse auswählen</a></p></div>';
  require __DIR__ . '/../partials/footer.php';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $studentId = (int)($_POST['student_id'] ?? 0);
  $len = (int)($_POST['pin_length'] ?? 6);
  if ($len < 4) $len = 4;
  if ($len > 8) $len = 8;

  $pin = trim((string)($_POST['new_pin'] ?? ''));
  if ($pin === '') $pin = gen_pin($len);

  if (!preg_match('/^\d{' . $len . '}$/', $pin)) {
    flash_set('error', "PIN muss genau {$len} Ziffern haben (oder Feld leer lassen für automatisch).");
    redirect(url('/teacher/passwords.php' . ($isAdmin && $selectedClass ? '?class=' . urlencode($selectedClass) : '')));
  }

  // Load student and validate scope
  $stmt = $pdo->prepare("SELECT id, first_name, last_name, username, class_name FROM users WHERE id=? AND role='student' AND is_active=1");
  $stmt->execute([$studentId]);
  $s = $stmt->fetch();

  if (!$s) {
    flash_set('error', 'Schüler:in nicht gefunden.');
    redirect(url('/teacher/passwords.php' . ($isAdmin && $selectedClass ? '?class=' . urlencode($selectedClass) : '')));
  }

  if (!$isAdmin && (string)$s['class_name'] !== (string)$selectedClass) {
    http_response_code(403);
    echo "Nicht erlaubt (falsche Klasse).";
    exit;
  }

  // Update password_hash with PIN and enforce change on next login
  $hash = password_hash($pin, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?");
  $stmt->execute([$hash, (int)$studentId]);

  // Audit event (do NOT store PIN)
  emit_event('teacher_student_password_reset', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'teacher',
    'actor_username' => (string)$u['username'],
    'actor_first_name' => (string)$u['first_name'],
    'actor_last_name' => (string)$u['last_name'],
    'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
    'target_user_id' => (int)$s['id'],
    'target_username' => (string)$s['username'],
    'target_first_name' => (string)$s['first_name'],
    'target_last_name' => (string)$s['last_name'],
    'target_name' => trim((string)$s['last_name'] . ', ' . (string)$s['first_name']),
    'target_class' => (string)$s['class_name'],
    'pin_length' => $len,
  ]);

  // Save last generated info in session for display (not stored in DB)
  $_SESSION['last_pw_reset'] = [
    'student' => $s['first_name'] . ' ' . $s['last_name'],
    'username' => $s['username'],
    'class' => $s['class_name'],
    'pin' => $pin,
    'len' => $len,
    'at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')
  ];

  flash_set('success', 'PIN gesetzt. Schüler:in muss beim nächsten Login ein neues Passwort vergeben.');
  redirect(url('/teacher/passwords.php' . ($isAdmin && $selectedClass ? '?class=' . urlencode($selectedClass) : '')));
}

// Class list (for admin)
$classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>'' AND is_active=1 ORDER BY class_name")->fetchAll();

// Students for class
$students = [];
if ($selectedClass !== '') {
  $stmt = $pdo->prepare("SELECT id, first_name, last_name, username, class_name, must_change_password FROM users WHERE role='student' AND class_name=? AND is_active=1 ORDER BY last_name, first_name");
  $stmt->execute([$selectedClass]);
  $students = $stmt->fetchAll();
}

$last = $_SESSION['last_pw_reset'] ?? null;
unset($_SESSION['last_pw_reset']);

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Schüler-PINs</h2>
  <p class="small">
    Hier kannst du für einzelne Schüler:innen ein <strong>kurzes Initial-Kennwort (PIN)</strong> setzen.
    Danach ist <strong>„Passwort ändern“ beim nächsten Login erzwungen</strong>.
  </p>

  <?php if ($last): ?>
    <div class="card">
      <h3>Neue PIN (bitte sofort weitergeben)</h3>
      <p><?= h($last['student']) ?> · <span class="badge"><?= h($last['class']) ?></span></p>
      <p class="small"><?= h($last['username']) ?> · <?= h($last['at']) ?></p>
      <p><span class="badge ok" style="font-family:monospace; font-size: 1.05em;"><?= h($last['pin']) ?></span></p>
      <p class="small">Hinweis: Diese PIN wird nur jetzt angezeigt.</p>
    </div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
    <div class="card">
      <h3>Klasse wählen (Admin)</h3>
      <form method="get">
        <label>Klasse</label>
        <select name="class" onchange="this.form.submit()">
          <option value="">-- wählen --</option>
          <?php foreach ($classes as $c): $cn=$c['class_name']; ?>
            <option value="<?= h($cn) ?>" <?= $selectedClass===$cn?'selected':'' ?>><?= h($cn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  <?php else: ?>
    <p>Klasse: <span class="badge"><?= h($selectedClass) ?></span></p>
  <?php endif; ?>

  <?php if ($selectedClass !== ''): ?>
    <div class="card">
      <h3>PIN setzen</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <label>Schüler:in</label>
        <select name="student_id" required>
          <option value="">-- wählen --</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['last_name'] . ', ' . $s['first_name']) ?> (<?= h($s['username']) ?>)</option>
          <?php endforeach; ?>
        </select>

        <div class="row">
          <div style="max-width:240px;">
            <label>PIN-Länge</label>
            <select name="pin_length">
              <?php for ($i=4; $i<=8; $i++): ?>
                <option value="<?= $i ?>" <?= $i===6?'selected':'' ?>><?= $i ?>-stellig</option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label>Neue PIN (leer = automatisch)</label>
            <input name="new_pin" inputmode="numeric" pattern="\d*" placeholder="z.B. 123456">
            <p class="small">Wenn du eine PIN eingibst, muss sie exakt der gewählten Länge entsprechen.</p>
          </div>
        </div>

        <p style="margin-top:12px;"><button type="submit">PIN setzen</button></p>
      </form>
    </div>

    <h3>Liste</h3>
    <table class="table">
      <thead><tr><th>Nr.</th><th>Name</th><th>Username</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($students as $i => $s): ?>
          <tr>
            <td data-label="Nr."><?= $i + 1 ?></td>
            <td><?= h($s['last_name'] . ', ' . $s['first_name']) ?></td>
            <td><?= h($s['username']) ?></td>
            <td>
              <?php if ((int)($s['must_change_password'] ?? 0) === 1): ?>
                <span class="badge warn">muss Passwort ändern</span>
              <?php else: ?>
                <span class="badge ok">ok</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?>
          <tr><td colspan="4" class="small">Keine Schüler:innen in dieser Klasse.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
