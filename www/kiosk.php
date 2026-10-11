<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/kiosk_access.php';

$pdo = db();
$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$kioskState = kiosk_access_state($pdo, $today);
$enabled = (bool)$kioskState['enabled'];
$validFrom = (string)$kioskState['valid_from'];
$validTo = (string)$kioskState['valid_to'];
$validToday = (bool)$kioskState['valid_today'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'end_kiosk') {
  csrf_check();
  logout_now();
  redirect(url('/kiosk.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_student') {
  csrf_check();
  kiosk_reset_student($pdo);
  session_regenerate_id(true);
  redirect(url('/kiosk.php'));
}

$verified = kiosk_session_grant_valid($pdo, $today);
if (!$verified && !empty($_SESSION['via_kiosk'])) {
  kiosk_clear_session_identity();
  session_regenerate_id(true);
}

// verify day code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
  csrf_check();
  $code = trim((string)($_POST['day_code'] ?? ''));
  $throttle = security_kiosk_status($pdo);
  if ($throttle['locked']) {
    $minutes = max(1, (int)ceil((int)$throttle['retry_after'] / 60));
    flash_set('error', 'Zu viele falsche Eingaben. Bitte in etwa ' . $minutes . ' Minute(n) erneut versuchen.');
    redirect(url('/kiosk.php'));
  }

  if ($validToday && kiosk_verify_school_code($pdo, $code) && kiosk_mark_verified($pdo, 'all', '', $today)) {
    security_kiosk_success($pdo);
    flash_set('success', 'Code ok. Bitte Klasse und Namen wählen.');
    redirect(url('/kiosk.php'));
  }

  // Class-specific kiosk codes (only for today)
  if ($validToday) {
    try {
      $stmt = $pdo->prepare("SELECT class_name, code_hash FROM kiosk_class_codes WHERE valid_date=?");
      $stmt->execute([$today]);
      $rows = $stmt->fetchAll();
      foreach ($rows as $r) {
        if (password_verify($code, (string)$r['code_hash'])
          && kiosk_mark_verified($pdo, 'class', (string)$r['class_name'], $today)) {
          security_kiosk_success($pdo);
          flash_set('success', 'Code ok. Bitte Namen wählen.');
          redirect(url('/kiosk.php'));
        }
      }
    } catch (Throwable $e) {
      // Table may not exist yet (upgrade not run). Ignore.
    }
  }
  $throttle = security_kiosk_failure($pdo);
  if ($throttle['locked']) {
    $minutes = max(1, (int)ceil((int)$throttle['retry_after'] / 60));
    flash_set('error', 'Zu viele falsche Eingaben. Bitte in etwa ' . $minutes . ' Minute(n) erneut versuchen.');
  } else {
    flash_set('error', 'Kennwort ungültig oder Kiosk nicht aktiv.');
  }
  redirect(url('/kiosk.php'));
}

$verified = kiosk_session_grant_valid($pdo, $today);
$scopeType = $verified ? (string)($_SESSION['kiosk_scope_type'] ?? 'all') : 'all';
$scopeClass = $verified ? (string)($_SESSION['kiosk_scope_class'] ?? '') : '';

// login student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login_student') {
  csrf_check();
  if (!$verified) {
    flash_set('error', 'Bitte zuerst Kiosk-Kennwort eingeben.');
    redirect(url('/kiosk.php'));
  }

  $class = trim((string)($_POST['class_name'] ?? ''));
  $studentId = (int)($_POST['student_id'] ?? 0);

  // enforce class scope if kiosk was opened with a class PIN
  if ($scopeType === 'class' && $scopeClass !== '') {
    $class = $scopeClass;
  }

  if ($class === '' || $studentId <= 0) {
    flash_set('error', 'Bitte Klasse und Namen wählen.');
    redirect(url('/kiosk.php'));
  }

  $stmt = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' AND class_name=? AND is_active=1");
  $stmt->execute([$studentId, $class]);
  if (!$stmt->fetchColumn()) {
    flash_set('error', 'Ungültige Auswahl.');
    redirect(url('/kiosk.php?class=' . urlencode($class)));
  }

  session_regenerate_id(true);
  $_SESSION['uid'] = $studentId;
  $_SESSION['via_kiosk'] = 1;
  redirect(url('/student/home.php'));
}

require __DIR__ . '/partials/header.php';
?>
<div class="card">
  <h2>Kiosk-Login (COOL-Tag)</h2>

  <div class="card">
    <h3>Was ist der Kiosk-Modus?</h3>
    <p>
      Der Kiosk-Modus ist für gemeinsam genutzte Schulgeräte gedacht, zum Beispiel für einen Computer im Klassenraum.
      Schüler:innen können sich hier ohne persönliches Passwort einloggen, ihren Namen auswählen und anschließend ihren COOL-Raum buchen.
    </p>
    <p class="small">
      Für Lehrer:innen ist der Kiosk praktisch, wenn mehrere Schüler:innen nacheinander am selben Gerät ihre Raumwahl eintragen sollen.
      Je nach Kennwort wird entweder die gesamte Klassenauswahl geöffnet oder nur eine einzelne Klasse freigeschaltet.
    </p>
    <p class="small">
      Wenn du Schüler:in bist, frage bitte deine Lehrerin oder deinen Lehrer, ob sie oder er den Kiosk-Modus auf einem zentralen Computer, zum Beispiel einem Raumcomputer, aktivieren kann.
    </p>
  </div>

  <?php if (!$validToday): ?>
    <p><span class="badge warn">Kiosk ist derzeit nicht aktiv</span></p>
    <p class="small">Admin kann Kiosk in <a href="<?= h(url('/admin/kiosk.php')) ?>">Admin → Kiosk</a> aktivieren und Tagescode setzen.</p>
  <?php endif; ?>

  <?php if (!$verified): ?>
    <div class="card">
      <h3>Kiosk-Kennwort eingeben</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify">
        <label>Kiosk-Kennwort (Tagescode oder Klassen-PIN)</label>
        <input name="day_code" autocomplete="one-time-code" required>
        <p style="margin-top:12px;"><button type="submit">Weiter</button></p>
      </form>
    </div>
  <?php else: ?>
    <?php      $selectedClass = '';
      $students = [];

      if ($scopeType === 'class' && $scopeClass !== '') {
        $selectedClass = $scopeClass;
      } else {
        $classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>'' AND is_active=1 ORDER BY class_name")->fetchAll();
        $selectedClass = (string)($_GET['class'] ?? '');
      }

      if ($selectedClass !== '') {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE role='student' AND class_name=? AND is_active=1 ORDER BY last_name, first_name");
        $stmt->execute([$selectedClass]);
        $students = $stmt->fetchAll();
      }
    ?>
    <div class="card">
      <h3>Klasse & Name wählen</h3><?php if ($scopeType !== 'class'): ?>


      <form method="get">
        <label>Klasse</label>
        <select name="class" onchange="this.form.submit()">
          <option value="">-- wählen --</option>
          <?php foreach ($classes as $c): $cn = $c['class_name']; ?>
            <option value="<?= h($cn) ?>" <?= $selectedClass===$cn?'selected':'' ?>><?= h($cn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php else: ?>
      <p>Klasse: <span class="badge"><?= h($selectedClass) ?></span></p>
<?php endif; ?>
<form method="post" style="margin-top:12px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="login_student">
        <input type="hidden" name="class_name" value="<?= h($selectedClass) ?>">

        <label>Name</label>
        <select name="student_id" required <?= $selectedClass===''?'disabled':'' ?>>
          <option value="">-- wählen --</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['last_name'] . ', ' . $s['first_name']) ?></option>
          <?php endforeach; ?>
        </select>

        <p style="margin-top:12px;">
          <button type="submit" <?= $selectedClass===''?'disabled':'' ?>>Einloggen</button>
        </p>
      </form>

      <p class="small">Nach der Raumwahl in der Schüleransicht auf „Kiosk“ klicken. Die aktuelle Person wird abgemeldet; die freigeschaltete Klasse und der Kioskzugang bleiben für die nächste Person erhalten.</p>
      <form method="post" action="<?= h(url('/kiosk.php')) ?>" class="small">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="end_kiosk">
        <span>Wenn niemand mehr buchen soll: </span><button type="submit" class="link-button">Kiosk beenden</button> (PIN-Freigabe entfernen).
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
