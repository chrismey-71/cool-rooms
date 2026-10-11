<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/kiosk_access.php';

$u = require_teacher();
$pdo = db();

// Ensure upgrade/migration was applied
try {
  $t = $pdo->query("SHOW TABLES LIKE 'kiosk_class_codes'")->fetchColumn();
  if (!$t) {
    require __DIR__ . '/../partials/header.php';
    echo '<div class="card"><h2>Klassen-Kiosk</h2><p class="badge warn">Upgrade erforderlich</p><p>Bitte zuerst <strong>/migrate_kiosk_class_codes.php</strong> als Admin ausführen und danach die Datei löschen/umbenennen.</p></div>';
    require __DIR__ . '/../partials/footer.php';
    exit;
  }
} catch (Throwable $e) {
  // ignore
}


function setting(string $k, string $default=''): string {
  $stmt = db()->prepare("SELECT v FROM app_settings WHERE k=?");
  $stmt->execute([$k]);
  $v = $stmt->fetchColumn();
  return ($v === false) ? $default : (string)$v;
}
function valid_date_or_default(string $value, string $default): string {
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return ($dt && $dt->format('Y-m-d') === $value) ? $value : $default;
}
function kiosk_range_dates(string $validFrom, string $validTo): array {
  $dates = [];
  $start = new DateTimeImmutable($validFrom);
  $end = new DateTimeImmutable($validTo);
  for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
    $dates[] = $day->format('Y-m-d');
  }
  return $dates;
}
kiosk_ensure_secure_storage($pdo);

/** Generate numeric PIN */
function gen_pin(int $len = 6): string {
  $min = 0;
  $max = (10 ** $len) - 1;
  $n = random_int($min, $max);
  return str_pad((string)$n, $len, '0', STR_PAD_LEFT);
}

// Determine responsible class
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
  echo '<div class="card"><h2>Klassen-Kiosk</h2><p>Du hast noch keine verantwortliche Klasse gewählt.</p><p><a href="'.h(url('/teacher/set_class.php')).'">Jetzt Klasse auswählen</a></p></div>';
  require __DIR__ . '/../partials/footer.php';
  exit;
}

$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$kioskEnabled = setting('kiosk_enabled', '0') === '1';
$kioskDate = setting('kiosk_expires', $today);
$validFrom = valid_date_or_default(setting('kiosk_valid_from', $kioskDate ?: $today), $today);
$validTo = valid_date_or_default(setting('kiosk_valid_to', $kioskDate ?: $today), $validFrom);
$targetDate = $today >= $validFrom && $today <= $validTo ? $today : $validFrom;
$targetRangeLabel = $validFrom === $validTo ? $validFrom : ($validFrom . ' bis ' . $validTo);
$rangeDates = kiosk_range_dates($validFrom, $validTo);

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $action = (string)($_POST['action'] ?? 'set');

  $class = trim((string)($_POST['class_name'] ?? ''));
  if (!$isAdmin) $class = $selectedClass;

  if ($class === '') {
    flash_set('error', 'Bitte Klasse auswählen.');
    redirect(url('/teacher/kiosk_class.php'));
  }

  if ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM kiosk_class_codes WHERE class_name=? AND valid_date BETWEEN ? AND ?");
    $stmt->execute([$class, $validFrom, $validTo]);

    emit_event('kiosk_class_pin_deleted', [
      'actor_id' => (int)$u['id'],
      'actor_role' => (string)($u['role'] ?? 'teacher'),
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'class' => $class,
      'valid_from' => $validFrom,
      'valid_to' => $validTo,
    ]);

    flash_set('success', 'Klassen-Kiosk-PIN gelöscht. Im Kiosk ist diese Klasse damit nicht mehr als „Klassen-Kiosk“ verfügbar.');
    redirect(url('/teacher/kiosk_class.php' . ($isAdmin ? ('?class=' . urlencode($class)) : '')));
  }

  $len = (int)($_POST['pin_length'] ?? 6);
  if ($len < 4) $len = 4;
  if ($len > 8) $len = 8;

  $pin = trim((string)($_POST['new_pin'] ?? ''));
  if ($pin === '') $pin = gen_pin($len);

  if (!preg_match('/^\d{' . $len . '}$/', $pin)) {
    flash_set('error', "Kiosk-PIN muss genau {$len} Ziffern haben (oder Feld leer lassen für automatisch).");
    redirect(url('/teacher/kiosk_class.php' . ($isAdmin ? ('?class=' . urlencode($class)) : '')));
  }

  $hash = password_hash($pin, PASSWORD_DEFAULT);
  $encrypted = secret_encrypt($pin);

  // upsert for (class, date)
  $stmt = $pdo->prepare("
    INSERT INTO kiosk_class_codes (class_name, valid_date, code_hash, code_encrypted, updated_by)
    VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash), code_encrypted=VALUES(code_encrypted), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP
  ");
  foreach ($rangeDates as $date) {
    $stmt->execute([$class, $date, $hash, $encrypted, (int)$u['id']]);
  }

  emit_event('kiosk_class_pin_set', [
    'actor_id' => (int)$u['id'],
    'actor_role' => (string)($u['role'] ?? 'teacher'),
    'class' => $class,
    'valid_from' => $validFrom,
    'valid_to' => $validTo,
    'dates_written' => count($rangeDates),
    'pin_length' => $len,
  ]);

  $_SESSION['last_kiosk_pin'] = [
    'class' => $class,
    'date' => $targetRangeLabel,
    'pin_encrypted' => secret_encrypt($pin),
    'len' => $len,
    'at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')
  ];

  flash_set('success', 'Klassen-Kiosk-PIN gesetzt. Im Kiosk ist danach nur diese Klasse auswählbar (wenn mit dieser PIN eingeloggt wird).');
  redirect(url('/teacher/kiosk_class.php' . ($isAdmin ? ('?class=' . urlencode($class)) : '')));
}

$last = $_SESSION['last_kiosk_pin'] ?? null;
unset($_SESSION['last_kiosk_pin']);
if (is_array($last)) {
  $lastPin = secret_decrypt((string)($last['pin_encrypted'] ?? ''));
  unset($last['pin_encrypted'], $last['pin']);
  if ($lastPin !== null && $lastPin !== '') {
    $last['pin'] = $lastPin;
  } else {
    $last = null;
  }
}

// For admin: class list
$classes = [];
if ($isAdmin) {
  $classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND is_active=1 AND class_name<>'' ORDER BY class_name")->fetchAll();
  if ($selectedClass === '' && $classes) $selectedClass = (string)$classes[0]['class_name'];
}

// Get info about the currently configured code.
$currentInfo = null;
$currentPin = '';
if ($selectedClass !== '') {
  $stmt = $pdo->prepare("
    SELECT COUNT(*) AS pin_count, MIN(valid_date) AS first_date, MAX(valid_date) AS last_date, MAX(updated_at) AS updated_at
    FROM kiosk_class_codes
    WHERE class_name=? AND valid_date BETWEEN ? AND ?
  ");
  $stmt->execute([$selectedClass, $validFrom, $validTo]);
  $currentInfo = $stmt->fetch() ?: null;
  if (!$currentInfo || (int)($currentInfo['pin_count'] ?? 0) === 0) {
    $currentInfo = null;
  } else {
    $currentPin = kiosk_class_code($pdo, $selectedClass, $targetDate);
  }
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Klassen-Kiosk</h2>

  <p class="small">
    Hier setzt du eine <strong>Kiosk-PIN für eine Klasse</strong>. Wenn Schüler:innen im Kiosk diese PIN eingeben,
    sehen sie anschließend <strong>nur diese Klasse</strong> in der Auswahl.
  </p>

  <div class="card">
    <h3>Wofür ist der Klassen-Kiosk gedacht?</h3>
    <p>
      Der Klassen-Kiosk ist für einen gemeinsam genutzten Klassen- oder Raumcomputer gedacht.
      Die Lehrkraft öffnet dort den Kiosk, gibt die Klassen-Kiosk-PIN selbst ein und lässt danach die Schüler:innen nacheinander ihren Namen und Raum wählen.
    </p>
    <p class="small">
      Dadurch müssen Schüler:innen am Klassenrechner weder persönliche Zugangsdaten kennen noch andere Klassen sehen.
      Besonders praktisch ist das, wenn mehrere Schüler:innen schnell nacheinander am selben Gerät ihre Raumwahl eintragen sollen.
    </p>
  </div>

  <div class="card">
    <p>
      Kiosk aktiv: <?= $kioskEnabled ? '<span class="badge ok">ja</span>' : '<span class="badge warn">nein</span>' ?>
      · Kiosk-Zeitraum: <span class="badge"><?= h($targetRangeLabel) ?></span>
    </p>
    <p class="small">Hinweis: Der Admin-Kiosk-Code für die gesamte Schule bleibt unverändert. Klassen-PINs sind zusätzliche Zugänge für genau eine Klasse und gelten für den angezeigten Zeitraum.</p>
  </div>

  <?php if ($last): ?>
    <div class="card">
      <h3>Neue Klassen-Kiosk-PIN (bitte sofort weitergeben)</h3>
      <p>Klasse: <span class="badge"><?= h($last['class']) ?></span> · Datum: <span class="badge"><?= h($last['date']) ?></span></p>
      <p class="small"><?= h($last['at']) ?></p>
      <p><span class="badge ok" style="font-family:monospace; font-size: 1.05em;"><?= h($last['pin']) ?></span></p>
      <p class="small">Die PIN wird geschützt gespeichert und kann im Lehrerbereich erneut angezeigt werden.</p>
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

  <div class="card">
    <h3>Kiosk-PIN setzen</h3>
    <?php if ($currentInfo): ?>
      <p class="small">Vorhandene PIN wurde zuletzt geändert am <?= h((string)$currentInfo['updated_at']) ?> und gilt in diesem Zeitraum für <?= (int)$currentInfo['pin_count'] ?> Tag(e).</p>
      <?php if ($currentPin !== ''): ?>
        <p>Aktuelle Klassen-Kiosk-PIN: <span class="badge ok" style="font-family:monospace; font-size: 1.05em;"><?= h($currentPin) ?></span></p>
      <?php else: ?>
        <p><span class="badge warn">PIN aus früherer Version nicht anzeigbar</span></p>
        <p class="small">Bitte setze die Klassen-Kiosk-PIN einmal neu, damit sie im Lehrerbereich angezeigt werden kann.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="small">Für diese Klasse ist noch keine PIN für diesen Kiosk-Zeitraum gesetzt.</p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="class_name" value="<?= h($selectedClass) ?>">
      <input type="hidden" name="action" value="set">

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
          <div class="small">Wenn du eine PIN eingibst, muss sie exakt der gewählten Länge entsprechen.</div>
        </div>
      </div>

      <p style="margin-top:12px;">
        <button type="submit" onclick="return confirm('Wirklich eine neue Kiosk-PIN setzen?')">Speichern</button>
        <?php if ($currentInfo): ?>
          <button type="submit" name="action" value="delete" class="danger" style="margin-left:8px;" onclick="return confirm('Kiosk-PIN für diese Klasse wirklich löschen?')">PIN löschen</button>
        <?php endif; ?>
      </p>
    </form>
  </div>

  <div class="card">
    <h3>Empfohlener Ablauf im Unterricht</h3>
    <ol class="small">
      <li>Öffne am Klassen- oder Raumcomputer <span class="badge"><?= h(url('/kiosk.php')) ?></span>.</li>
      <li>Gib die Klassen-Kiosk-PIN selbst ein, damit nur deine Klasse freigeschaltet wird. Die PIN sollte nicht an die Klasse verteilt werden.</li>
      <li>Lass eine Schülerin oder einen Schüler den eigenen Namen auswählen und den gewünschten Raum buchen.</li>
      <li>Nach der Raumwahl meldet sich diese Person über „Abmelden“ wieder zurück zum Kiosk. Danach ist die nächste Person an der Reihe.</li>
      <li>Wenn alle fertig sind, schließe den Kiosk oder melde das Gerät bewusst ab, damit keine weitere Raumwahl unbeaufsichtigt erfolgt.</li>
      <li>PIN-Arten:
        <ul>
          <li><strong>Admin-Kiosk-Code</strong> → Auswahl aller Klassen</li>
          <li><strong>Klassen-PIN</strong> → Auswahl nur dieser Klasse</li>
        </ul>
      </li>
    </ol>
  </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
