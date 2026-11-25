<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

$u = require_admin();
$pdo = db();

function qs(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  $s = http_build_query($q);
  return $s ? ('?' . $s) : '';
}

$via = (string)($_GET['via_kiosk'] ?? 'any'); // any|1|0
$type = trim((string)($_GET['type'] ?? '')); // optional
$class = trim((string)($_GET['class'] ?? '')); // optional
$student = trim((string)($_GET['student'] ?? '')); // optional (id or username)
$from = trim((string)($_GET['from'] ?? '')); // YYYY-MM-DD
$to = trim((string)($_GET['to'] ?? ''));     // YYYY-MM-DD
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// sensible defaults: today
$today = (new DateTimeImmutable('now'))->format('Y-m-d');
if ($from === '') $from = $today;
if ($to === '') $to = $today;

// dropdown sources
$classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>'' ORDER BY class_name")->fetchAll(PDO::FETCH_COLUMN);
$students = [];
if ($class !== '') {
  $stmt = $pdo->prepare("SELECT id, last_name, first_name, username FROM users WHERE role='student' AND class_name=? ORDER BY last_name, first_name");
  $stmt->execute([$class]);
  $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Event type labels for user-friendly dropdown / display
$TYPE_LABELS = [
  'assignment' => 'Raum gewählt / geändert',
  'room_change' => 'Raum gewechselt',
  'teacher_location' => 'Lehrkraft: Standort gesetzt',
  'teacher_timetable_apply' => 'Lehrkraft: Stundenplantermin übernommen',
  'password_change' => 'Passwort geändert',
  'teacher_student_password_reset' => 'Lehrkraft: Schüler-PIN/Passwort zurückgesetzt',
  'teacher_class_assignments_reset' => 'Lehrkraft: Raumzuordnungen der Klasse/Gruppenauswahl gelöscht',
  'teacher_class_home_room_booking' => 'Lehrkraft: Klasse/Gruppenauswahl in Klassenraum gebucht',
  'class_groups_changed' => 'Admin: Klassengruppen oder Gruppenzuordnungen geändert',
  'teacher_student_active_change' => 'Lehrkraft: Anwesenheit geändert',
  'teacher_responsible_class_change' => 'Lehrkraft: Verantwortliche Klasse geändert',
  'kiosk_login' => 'Kiosk: PIN eingegeben',
  'kiosk_class_pin_set' => 'Kiosk: Klassen-PIN gesetzt',
  'kiosk_class_pin_deleted' => 'Kiosk: Klassen-PIN gelöscht',
  'admin_class_passwords_change' => 'Admin: Klassen-Kennwörter geändert',
  'admin_kiosk_school_pin_change' => 'Admin: Kiosk-Schulcode geändert',
  'admin_class_updated' => 'Admin: Klasse umbenannt oder Klassenraum geändert',
  'admin_class_deleted' => 'Admin: Klasse gelöscht',
  'admin_student_created' => 'Admin: Schüler angelegt',
  'admin_student_updated' => 'Admin: Schüler geändert',
  'admin_student_active_toggled' => 'Admin: Anwesenheit geändert',
  'admin_class_active_set' => 'Admin: Klassen-Anwesenheit geändert',
  'admin_student_deleted' => 'Admin: Schüler gelöscht',
  'admin_room_created' => 'Admin: Raum angelegt',
  'admin_room_updated' => 'Admin: Raum geändert',
  'admin_room_deleted' => 'Admin: Raum gelöscht',
  'admin_teacher_created' => 'Admin: Lehrer angelegt',
  'admin_teacher_updated' => 'Admin: Lehrer geändert',
  'admin_teacher_deleted' => 'Admin: Lehrer gelöscht',
  'reset' => 'Reset: Raumzuordnungen gelöscht',
  'admin_reset_all_assignments' => 'Admin: COOL-Tag zurückgesetzt',
  'cron_reset_all_assignments' => 'Automatik: COOL-Tag zurückgesetzt',
];

$types = $pdo->query("SELECT DISTINCT type FROM events ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);

function type_label(string $type, array $labels): string {
  $type = trim($type);
  if ($type === '') return '';
  return $labels[$type] ?? $type;
}

// Build SQL with safe filters (avoid JSON functions for compatibility)
$where = [];
$params = [];

$where[] = "created_at >= ? AND created_at < (DATE_ADD(?, INTERVAL 1 DAY))";
$params[] = $from . " 00:00:00";
$params[] = $to . " 00:00:00";

if ($via === '1' || $via === '0') {
  $where[] = "via_kiosk = ?";
  $params[] = (int)$via;
}
if ($type !== '') {
  $where[] = "type = ?";
  $params[] = $type;
}
if ($class !== '') {
  // match payload containing class_name field
  $where[] = "payload LIKE ?";
  $params[] = '%"class_name":"' . str_replace(['%','_'], ['\%','\_'], $class) . '"%';
}
if ($student !== '') {
  if (ctype_digit($student)) {
    // student_id numeric
    $where[] = "payload LIKE ?";
    $params[] = '%"student_id":' . $student . '%';
  } else {
    // username string
    $where[] = "payload LIKE ?";
    $params[] = '%"username":"' . str_replace(['%','_'], ['\%','\_'], $student) . '"%';
  }
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Count for pagination (bounded by filters)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// Fetch page
$stmt = $pdo->prepare("
  SELECT id, created_at, type, payload,
         client_ip, client_os, client_device, client_id,
         via_kiosk, kiosk_scope_type, kiosk_scope_class
  FROM events
  $whereSql
  ORDER BY id DESC
  LIMIT ? OFFSET ?
");
foreach ($params as $index => $value) {
  $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resolve IDs to usernames (cache) so we can display readable persons.
$userCache = [];
function username_for(PDO $pdo, array &$cache, int $id): string {
  if ($id <= 0) return '';
  if (isset($cache[$id])) return (string)$cache[$id];
  $st = $pdo->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $u = (string)($st->fetchColumn() ?: '');
  $cache[$id] = $u;
  return $u;
}


// helper to parse payload safely
function payload(array $r): array {
  $p = [];
  if (!empty($r['payload'])) {
    $tmp = json_decode((string)$r['payload'], true);
    if (is_array($tmp)) $p = $tmp;
  }

if (!function_exists('short_ip')) {
  function short_ip(string $ip): string {
    $ip = trim($ip);
    if ($ip === '') return '';

    // IPv6 (contains ':') – show beginning + end
    if (strpos($ip, ':') !== false) {
      if (strlen($ip) <= 18) return $ip;
      return substr($ip, 0, 12) . '…' . substr($ip, -6);
    }

    // IPv4 – show first 2 octets + last octet
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
      return $parts[0] . '.' . $parts[1] . '.….' . $parts[3];
    }

    // Fallback
    if (strlen($ip) <= 15) return $ip;
    return substr($ip, 0, 7) . '…' . substr($ip, -3);
  }
}



  return $p;
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Eventauswertung</h2>

  <form method="get" class="row" style="gap:10px; flex-wrap:wrap; align-items:flex-end;">
    <div>
      <label>Von</label>
      <input type="date" name="from" value="<?= h($from) ?>">
    </div>
    <div>
      <label>Bis</label>
      <input type="date" name="to" value="<?= h($to) ?>">
    </div>
    <div>
      <label>Kioskmodus</label>
      <select name="via_kiosk">
        <option value="any" <?= $via==='any'?'selected':'' ?>>alle</option>
        <option value="1" <?= $via==='1'?'selected':'' ?>>nur Kiosk</option>
        <option value="0" <?= $via==='0'?'selected':'' ?>>ohne Kiosk</option>
      </select>
    </div>
    <div>
      <label>Klasse</label>
      <select name="class" onchange="this.form.submit()">
        <option value="">alle</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= h($c) ?>" <?= $class===$c?'selected':'' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Schüler:in</label>
      <select name="student">
        <option value="">alle</option>
        <?php foreach ($students as $s): ?>
          <option value="<?= h((string)$s['id']) ?>" <?= $student===(string)$s['id']?'selected':'' ?>>
            <?= h($s['last_name'] . ' ' . $s['first_name'] . ' (' . $s['username'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="small">Tipp: Klasse auswählen, dann Schülerliste.</p>
    </div>
    <div>
      <label>Typ</label>
      <select name="type" style="min-width:240px;">
        <option value="">alle</option>
        <?php foreach ($types as $t): ?>
          <?php $lbl = type_label((string)$t, $TYPE_LABELS); ?>
          <option value="<?= h((string)$t) ?>" <?= ($type === (string)$t) ? 'selected' : '' ?>>
            <?= h($lbl) ?> (<?= h((string)$t) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button class="primary">Filtern</button>
      <a class="secondary" href="<?= h(url('/admin/events.php')) ?>" style="margin-left:6px;">Reset</a>
    </div>
  </form>

  <p class="small">Treffer: <strong><?= (int)$total ?></strong> · Seite <?= (int)$page ?>/<?= (int)$totalPages ?></p>

  <table class="table">
    <thead>
      <tr>
        <th>Zeit</th>
        <th>Typ</th>
        <th>Klasse</th>
        <th>Ausgeführt von</th>
        <th>Person</th>
        <th>Raum</th>
        <th>Kiosk</th>
        <th>Gerät</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="small">Keine Events für diese Filter.</td></tr>
      <?php endif; ?>

      <?php foreach ($rows as $r): $p = payload($r); ?>
        <?php
          $className = (string)($p['class_name'] ?? ($p['target_class'] ?? ($r['kiosk_scope_class'] ?? '')));

          // Actor (who executed the action)
          $actor = '';
          if (!empty($p['actor_name']) || !empty($p['actor_first_name']) || !empty($p['actor_last_name']) || !empty($p['actor_username'])) {
            $an = trim((string)($p['actor_name'] ?? ''));
            if ($an === '') $an = trim((string)($p['actor_last_name'] ?? '') . ' ' . (string)($p['actor_first_name'] ?? ''));
            $au = trim((string)($p['actor_username'] ?? ''));
            $actor = trim($an);
            if ($au !== '' && stripos($actor, $au) === false) $actor .= ($actor !== '' ? ' (' . $au . ')' : $au);
            $actor = trim($actor);
          } elseif (!empty($p['actor_id'])) {
            $aid = (int)$p['actor_id'];
            $un = username_for($pdo, $userCache, $aid);
            $actor = $un !== '' ? $un : ('ID ' . (string)$aid);
          } elseif (!empty($r['via_kiosk'])) {
            $actor = 'Kiosk';
          }

          $person = '';

          // Prefer explicit target snapshot (for admin/teacher actions) so names remain readable even if users are renamed/deleted later
          if (!empty($p['target_name']) || !empty($p['target_first_name']) || !empty($p['target_last_name']) || !empty($p['target_username'])) {
            $tn = trim((string)($p['target_name'] ?? ''));
            if ($tn === '') $tn = trim((string)($p['target_last_name'] ?? '') . ' ' . (string)($p['target_first_name'] ?? ''));
            $tu = trim((string)($p['target_username'] ?? ''));
            $person = trim($tn);
            if ($tu !== '' && stripos($person, $tu) === false) $person .= ($person !== '' ? ' (' . $tu . ')' : $tu);
            $person = trim($person);
          } elseif (!empty($p['last_name']) || !empty($p['first_name'])) {
            $person = trim((string)($p['last_name'] ?? '') . ' ' . (string)($p['first_name'] ?? ''));
          } elseif (!empty($p['username'])) {
            $person = (string)$p['username'];
          } elseif (!empty($p['student_id'])) {
            $sid = (int)$p['student_id'];
            $un = username_for($pdo, $userCache, $sid);
            $person = $un !== '' ? $un : ('ID ' . (string)$sid);
          } elseif (!empty($p['teacher_id'])) {
            $tid = (int)$p['teacher_id'];
            $un = username_for($pdo, $userCache, $tid);
            $person = $un !== '' ? $un : ('Lehrer-ID ' . (string)$tid);
          }

          $room = (string)($p['room_name'] ?? '');
          if ($room === '' && isset($p['room_id'])) $room = 'Raum-ID ' . (string)$p['room_id'];

          $kiosk = ((int)($r['via_kiosk'] ?? 0) === 1) ? 'ja' : 'nein';
          if ($kiosk === 'ja' && !empty($r['kiosk_scope_type'])) {
            $kiosk .= ' (' . (string)$r['kiosk_scope_type'] . ($r['kiosk_scope_class'] ? (':' . (string)$r['kiosk_scope_class']) : '') . ')';
          }

          $device = trim((string)($r['client_device'] ?? '') . ' / ' . (string)($r['client_os'] ?? ''));
          $cid = (string)($r['client_id'] ?? '');
          if ($cid !== '') $device .= ' · ' . substr($cid, 0, 8) . '…';
        ?>
        <tr>
          <td class="small"><?= h((string)$r['created_at']) ?></td>
          <td><?= h(type_label((string)$r['type'], $TYPE_LABELS)) ?><div class="muted" style="font-size:12px;"><?= h((string)$r['type']) ?></div></td>
          <td><?= h($className) ?></td>
          <td><?= h($actor) ?></td>
          <td><?= h($person) ?></td>
          <td><?= h($room) ?></td>
          <td class="small"><?= h($kiosk) ?></td>
          <td class="small"><?= h($device) ?></td>
          <td class="small"><?php $ip=(string)($r['client_ip'] ?? ''); ?><span title="<?= h($ip) ?>"><?= h(short_ip($ip)) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="row" style="justify-content:space-between; align-items:center;">
    <div class="small">
      <?php if ($page > 1): ?>
        <a href="<?= h(url('/admin/events.php' . qs(['page'=>$page-1]))) ?>">← Zurück</a>
      <?php endif; ?>
    </div>
    <div class="small">
      <?php if ($page < $totalPages): ?>
        <a href="<?= h(url('/admin/events.php' . qs(['page'=>$page+1]))) ?>">Weiter →</a>
      <?php endif; ?>
    </div>
  </div>

  <details style="margin-top:14px;">
    <summary class="small">Details / Hinweise</summary>
    <ul class="small">
      <li>Events werden automatisch nach ca. <strong>7 Tagen</strong> bereinigt.</li>
      <li>Filter „Klasse/Schüler:in“ nutzt eine einfache Suche im JSON-<code>payload</code> (kompatibel ohne JSON-Funktionen).</li>
      <li><code>client_id</code> ist eine per Cookie erzeugte Gerätekennung (erste 8 Zeichen werden angezeigt).</li>
    </ul>
  </details>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
