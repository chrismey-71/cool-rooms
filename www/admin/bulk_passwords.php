<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/branding.php';

// Optional: Event logging
$eventsPath = __DIR__ . '/../lib/events.php';
if (file_exists($eventsPath)) {
  require_once $eventsPath;
}

$u = require_admin();
$pdo = db();

/**
 * Generate a short numeric PIN (no leading zeros restriction).
 * Length default: 6.
 */
function gen_pin(int $len = 6): string {
  $min = 0;
  $max = (10 ** $len) - 1;
  $n = random_int($min, $max);
  return str_pad((string)$n, $len, '0', STR_PAD_LEFT);
}

function default_qr_url(): string {
  $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
  $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host) ?? '';
  if ($host === '') {
    return '';
  }

  $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
  if ($forwardedProto !== '') {
    $forwardedProto = strtolower(trim(explode(',', $forwardedProto)[0]));
  }

  if ($forwardedProto === 'http' || $forwardedProto === 'https') {
    $scheme = $forwardedProto;
  } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
    $scheme = 'https';
  } else {
    $requestScheme = strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
    $scheme = ($requestScheme === 'http' || $requestScheme === 'https') ? $requestScheme : 'https';
  }

  return rtrim($scheme . '://' . $host . base_url(), '/') . '/';
}

$classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND is_active=1 AND class_name<>'' ORDER BY class_name")->fetchAll();

$selectedClass = trim((string)($_GET['class'] ?? ''));
$show = $_SESSION['bulk_pw'] ?? null;
$defaultQrUrl = default_qr_url();


/** PDF export for the current list (stored in session after generation). */
if (isset($_GET['pdf']) && $_GET['pdf'] === '1') {
  $show = $_SESSION['bulk_pw'] ?? null;
  if (!$show || !is_array($show) || empty($show['rows'])) {
    flash_set('error', 'Keine Liste zum Exportieren vorhanden. Bitte Kennwörter generieren.');
    redirect(url('/admin/bulk_passwords.php'));
  }

  require_once __DIR__ . '/../lib/simple_pdf.php';

  $class = (string)($show['class_name'] ?? 'klasse');
  $created = (string)($show['created_at'] ?? '');
  $len = (int)($show['pin_length'] ?? 0);
  $rows = (array)$show['rows'];

  // Optional: QR code URL to print on the PDF (top center)
  $qrUrl = trim((string)($show['qr_url'] ?? ''));
  if ($qrUrl === '') {
    $qrUrl = $defaultQrUrl;
  }

  $pdfData = simple_pdf_bulk_passwords($class, $len, $created, $rows, $qrUrl);

  // Safe filename
  $safeClass = preg_replace('/[^A-Za-z0-9_-]+/', '_', $class) ?: 'klasse';
  $brandTitle = (string)(branding_settings()['brand_title'] ?? 'COOL Rooms');
  $safeTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', $brandTitle) ?: 'COOL_Rooms';
  $fname = $safeTitle . '_Kennwoerter_' . $safeClass . '_' . date('Ymd_His') . '.pdf';

  // Some hosting setups enable output buffering / gzip compression.
  // A Content-Length header can then cause truncated downloads (blank PDF).
  // To be robust, clear output buffers and do NOT send Content-Length.
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo $pdfData;
  exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  if (($_POST['action'] ?? '') === 'clear') {
    unset($_SESSION['bulk_pw']);
    redirect(url('/admin/bulk_passwords.php'));
  }

  $class = trim((string)($_POST['class_name'] ?? ''));
  $len = (int)($_POST['pin_length'] ?? 6);
  $qrUrl = trim((string)($_POST['qr_url'] ?? ''));
  if ($qrUrl === '') {
    $qrUrl = $defaultQrUrl;
  }
  if ($len < 4) $len = 4;
  if ($len > 8) $len = 8;

  if ($class === '') {
    flash_set('error', 'Bitte eine Klasse auswählen.');
    redirect(url('/admin/bulk_passwords.php'));
  }

  // Load students
  $stmt = $pdo->prepare("SELECT id, first_name, last_name, username, class_name FROM users WHERE role='student' AND is_active=1 AND class_name=? ORDER BY last_name, first_name");
  $stmt->execute([$class]);
  $students = $stmt->fetchAll();

  if (!$students) {
    flash_set('error', 'Keine aktiven Schüler:innen in dieser Klasse gefunden.');
    redirect(url('/admin/bulk_passwords.php'));
  }

  // Generate unique PINs within batch
  $pins = [];
  $rows = [];
  foreach ($students as $s) {
    do {
      $pin = gen_pin($len);
    } while (isset($pins[$pin]));
    $pins[$pin] = true;

    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?");
    $upd->execute([$hash, (int)$s['id']]);

    $rows[] = [
      'last_name' => $s['last_name'],
      'first_name' => $s['first_name'],
      'username' => ($s['username'] ?? ''),
      'class_name' => $s['class_name'],
      'pin' => $pin
    ];
  }


  // Event: Admin setzt kurze Kennwörter pro Klasse (ohne Kennwörter zu speichern)
  if (function_exists('emit_event')) {
    $actorName = trim(($u['last_name'] ?? '') . ', ' . ($u['first_name'] ?? ''));
    emit_event('admin_class_passwords_change', [
      'actor_id'       => (int)($u['id'] ?? 0),
      'actor_role'     => 'admin',
      'actor_username' => (string)($u['username'] ?? ''),
      'actor_name'     => $actorName,
      'class'          => $class,
      'pin_length'     => $len,
      'count'          => count($rows),
    ]);
  }

  $_SESSION['bulk_pw'] = [
    'class_name' => $class,
    'pin_length' => $len,
    'created_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    'qr_url' => $qrUrl,
    'rows' => $rows
  ];

  flash_set('success', 'Kurze Kennwörter wurden gesetzt. Schüler:innen müssen nach dem Login ihr Passwort ändern.');
  redirect(url('/admin/bulk_passwords.php?class=' . urlencode($class)));
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Kennwörter pro Klasse setzen</h2>
  <p class="small">
    Diese Funktion setzt für <strong>alle Schüler:innen einer Klasse</strong> ein neues <strong>kurzes Initial-Kennwort (PIN)</strong>.
    Danach ist <strong>„Passwort ändern“ beim nächsten Login erzwungen</strong>.
  </p>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="row">
        <div>
          <label>Klasse</label>
          <select name="class_name" required>
            <option value="">-- wählen --</option>
            <?php foreach ($classes as $c): $cn=$c['class_name']; ?>
              <option value="<?= h($cn) ?>" <?= $selectedClass===$cn?'selected':'' ?>><?= h($cn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="max-width:240px;">
          <label>PIN-Länge</label>
          <select name="pin_length">
            <?php for ($i=4; $i<=8; $i++): ?>
              <option value="<?= $i ?>" <?= ($show && (int)$show['pin_length']===$i) ? 'selected' : ($i===6 ? 'selected' : '') ?>><?= $i ?>-stellig</option>
            <?php endfor; ?>
          </select>
        </div>

        <div style="max-width:420px;">
          <label>QR-Link (optional, erscheint im PDF)</label>
          <input type="text" name="qr_url" placeholder="<?= h($defaultQrUrl) ?>" value="<?= h((string)(($show['qr_url'] ?? '') ?: $defaultQrUrl)) ?>">
          <div class="small">Tipp: Hier kannst du auch einen Link zur App-/Login-Seite eintragen.</div>
        </div>
      </div>

      <p style="margin-top:12px;">
        <button type="submit" onclick="return confirm('Wirklich für alle Schüler:innen der Klasse neue Kennwörter setzen?')">Kennwörter generieren</button>
      </p>
    </form>
  </div>

  <?php if ($show): ?>
    <div class="card" id="printArea">
      <div class="row" style="align-items:flex-start;">
        <div>
          <h3>Druckliste</h3>
          <p class="small">
            Klasse: <span class="badge"><?= h($show['class_name']) ?></span>
            · PIN-Länge: <span class="badge"><?= h((string)$show['pin_length']) ?></span>
            · Erstellt: <span class="badge"><?= h($show['created_at']) ?></span>
          </p>
        </div>
        <div style="text-align:right;">
          <button type="button" onclick="window.print()">Drucken</button>
          <a class="button" href="<?= h(url('/admin/bulk_passwords.php?pdf=1')) ?>" style="margin-left:8px;">PDF</a>
          <form method="post" style="display:inline; margin-left:8px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="secondary">Liste schließen</button>
          </form>
        </div>
      </div>

      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Nachname</th>
            <th>Vorname</th>
            <th>Username</th>
            <th>Initial-Kennwort</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; foreach ($show['rows'] as $r): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= h($r['last_name']) ?></td>
              <td><?= h($r['first_name']) ?></td>
              <td><?= h($r['username'] ?? '') ?></td>
              <td><span class="badge ok" style="font-family:monospace; font-size: 1.05em;"><?= h($r['pin']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="small" style="margin-top:10px;">
        Hinweis: Diese Liste wird aus Sicherheitsgründen nur einmal angezeigt (nach dem Generieren). Bitte sofort drucken/abspeichern.
      </p>
    </div>

    <style>
      @media print {
        nav, .flash, .card form, a.button, button { display: none !important; }
        body { background: #fff !important; }
        .container { padding: 0 !important; }
        #printArea { box-shadow: none !important; border: none !important; }
        table { font-size: 12px; }
      }
    </style>
  <?php endif; ?>

</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
