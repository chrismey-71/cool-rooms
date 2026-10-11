<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

$u = require_login();
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$teachers = [];

if ($q !== '') {
  $like = '%' . $q . '%';
  $stmt = $pdo->prepare("
    SELECT t.id, t.first_name, t.last_name, t.username,
           r.name AS room_name, tl.updated_at
    FROM users t
    LEFT JOIN teacher_locations tl ON tl.teacher_id = t.id
    LEFT JOIN rooms r ON r.id = tl.room_id
    WHERE t.role='teacher' AND t.is_active=1
      AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.username LIKE ?)
    ORDER BY t.last_name, t.first_name
  ");
  $stmt->execute([$like, $like, $like]);
  $teachers = $stmt->fetchAll();
} else {
  $teachers = $pdo->query("
    SELECT t.id, t.first_name, t.last_name, t.username,
           r.name AS room_name, tl.updated_at
    FROM users t
    LEFT JOIN teacher_locations tl ON tl.teacher_id = t.id
    LEFT JOIN rooms r ON r.id = tl.room_id
    WHERE t.role='teacher' AND t.is_active=1
    ORDER BY t.last_name, t.first_name
  ")->fetchAll();
}

require __DIR__ . '/partials/header.php';
?>
<div class="card">
  <h2>Lehrer finden</h2>
  <p class="small">Hier siehst du, wo Lehrkräfte gerade sind. Wenn nichts steht: „nicht eingebucht“.</p>

  <div class="card">
    <form method="get" class="row" style="align-items:end;">
      <div>
        <label>Suche</label>
        <input name="q" value="<?= h($q) ?>" placeholder="Name oder Username">
      </div>
      <div style="min-width:160px;">
        <button type="submit">Suchen</button>
        <?php if ($q !== ''): ?>
          <a class="button" href="<?= h(url('/teachers.php')) ?>" style="margin-left:8px;">Zurücksetzen</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <table class="table">
    <thead><tr><th>Name</th><th>Username</th><th>Raum</th><th>Stand</th></tr></thead>
    <tbody>
      <?php foreach ($teachers as $t): ?>
        <tr>
          <td><?= h($t['last_name'] . ', ' . $t['first_name']) ?></td>
          <td><?= h($t['username']) ?></td>
          <td>
            <?php if ($t['room_name']): ?>
              <span class="badge ok"><?= h($t['room_name']) ?></span>
            <?php else: ?>
              <span class="badge warn">nicht eingebucht</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= h($t['updated_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$teachers): ?>
        <tr><td colspan="4" class="small">Keine Lehrkräfte gefunden.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($u['role']==='teacher' || is_admin_user($u)): ?>
    <p class="small" style="margin-top:10px;">Tipp: <a href="<?= h(url('/teacher/location.php')) ?>">Eigenen Raum setzen</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
