<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

$u = require_admin();
$pdo = db();

$class = (string)($_GET['class'] ?? '');

$classes = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name IS NOT NULL AND class_name<>'' ORDER BY class_name")->fetchAll();

$params = [];
$where = "u.role='student'";
if ($class !== '') {
  $where .= " AND u.class_name = ?";
  $params[] = $class;
}

$stmt = $pdo->prepare("
  SELECT u.id, u.first_name, u.last_name, u.username, u.class_name, u.is_active,
         r.name AS room_name, ra.updated_at
  FROM users u
  LEFT JOIN room_assignments ra ON ra.student_id = u.id
  LEFT JOIN rooms r ON r.id = ra.room_id
  WHERE $where
  ORDER BY u.class_name, u.last_name, u.first_name
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Summary counts by room for current filter
$stmt = $pdo->prepare("
  SELECT COALESCE(r.name, 'nicht eingebucht') AS room_name, COUNT(*) AS cnt
  FROM users u
  LEFT JOIN room_assignments ra ON ra.student_id = u.id
  LEFT JOIN rooms r ON r.id = ra.room_id
  WHERE $where
  GROUP BY room_name
  ORDER BY cnt DESC, room_name
");
$stmt->execute($params);
$summary = $stmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Alle Schüler:innen in Räumen</h2>
  <p class="small">Filtere nach Klasse und sieh sofort, wer wo ist – inkl. „nicht eingebucht“.</p>

  <form method="get" class="row" style="align-items:end;gap:12px">
    <div style="min-width:240px">
      <label>Klasse</label>
      <select name="class" onchange="this.form.submit()">
        <option value="">-- alle Klassen --</option>
        <?php foreach ($classes as $c): $cn = (string)($c['class_name'] ?? ''); ?>
          <option value="<?= h($cn) ?>" <?= $class === $cn ? 'selected' : '' ?>><?= h($cn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1">
      <label class="muted">Kurzinfo</label>
      <div class="small">
        <?= count($rows) ?> Schüler:innen · <?= $class !== '' ? 'Klasse: <span class="badge">'.h($class).'</span>' : '<span class="badge">alle</span>' ?>
      </div>
    </div>
  </form>

  <div class="card">
    <h3>Belegung (Übersicht)</h3>
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <?php foreach ($summary as $s): ?>
        <span class="badge"><?= h((string)$s['room_name']) ?>: <?= h((string)$s['cnt']) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h3>Liste</h3>
    <table class="table">
      <thead><tr>
        <th>Nr.</th><th>Klasse</th><th>Name</th><th>Username</th><th>Anwesenheit</th><th>Raum</th><th>Stand</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td data-label="Nr."><?= $i + 1 ?></td>
            <td><?= h((string)($r['class_name'] ?? '')) ?></td>
            <td><?= h((string)$r['last_name'] . ', ' . (string)$r['first_name']) ?></td>
            <td><?= h((string)$r['username']) ?></td>
            <td><?= ((int)($r['is_active'] ?? 1)===1) ? '<span class="badge ok">anwesend</span>' : '<span class="badge warn">nicht anwesend</span>' ?></td>
            <td>
              <?php if (!empty($r['room_name'])): ?>
                <span class="badge ok"><?= h((string)$r['room_name']) ?></span>
              <?php else: ?>
                <span class="badge warn">nicht eingebucht</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= h((string)($r['updated_at'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
