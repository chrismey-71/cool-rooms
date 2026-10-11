<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';

$u = require_admin();
$pdo = db();

$sort = (string)($_GET['sort'] ?? 'last_name'); // first_name | last_name
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$allowedSort = ['first_name', 'last_name'];
if (!in_array($sort, $allowedSort, true)) $sort = 'last_name';

function qs(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  $s = http_build_query($q);
  return $s ? ('?' . $s) : '';
}

function admin_account_count(PDO $pdo): int {
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' OR (role='teacher' AND is_admin=1)")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $fn = trim((string)($_POST['first_name'] ?? ''));
    $ln = trim((string)($_POST['last_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $pass = (string)($_POST['password'] ?? '');

    if ($fn==='' || $ln==='' || $username==='') {
      flash_set('error', 'Bitte Vorname/Nachname/Username ausfüllen.');
      redirect(url('/admin/teachers.php'));
    }

  if ($pass !== '' && mb_strlen($pass, '8bit') < 8) {
    flash_set('error', 'Passwort muss mindestens 8 Zeichen haben.');
    redirect(url('/admin/teachers.php'));
  }

    if ($id > 0) {
      $stmtOld = $pdo->prepare("SELECT first_name,last_name,username,role,is_admin FROM users WHERE id=? AND role IN ('teacher','admin') LIMIT 1");
      $stmtOld->execute([$id]);
      $old = $stmtOld->fetch() ?: null;

      if (!$old) {
        flash_set('error', 'Konto nicht gefunden.');
        redirect(url('/admin/teachers.php'));
      }

      if ((string)$old['role'] === 'admin') {
        $is_admin = 1;
      }

      if ($pass !== '') {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=?, is_admin=?, password_hash=? WHERE id=? AND role IN ('teacher','admin')");
        $stmt->execute([$fn,$ln,$username,$is_admin,$hash,$id]);
      } else {
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=?, is_admin=? WHERE id=? AND role IN ('teacher','admin')");
        $stmt->execute([$fn,$ln,$username,$is_admin,$id]);
      }
      flash_set('success', 'Konto gespeichert.');

      emit_event('admin_teacher_updated', [
        'actor_id' => (int)$u['id'],
        'actor_role' => 'admin',
        'actor_username' => (string)$u['username'],
        'actor_first_name' => (string)$u['first_name'],
        'actor_last_name' => (string)$u['last_name'],
        'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
        'target_user_id' => $id,
        'target_username' => $username,
        'target_first_name' => $fn,
        'target_last_name' => $ln,
        'target_name' => trim($ln . ', ' . $fn),
        'old' => $old,
        'new' => [
          'first_name' => $fn,
          'last_name' => $ln,
          'username' => $username,
          'is_admin' => $is_admin,
          'password_changed' => ($pass !== ''),
        ],
      ]);
    } else {
      if ($pass === '') $pass = bin2hex(random_bytes(4));
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO users (role, first_name, last_name, username, password_hash, is_admin) VALUES ('teacher',?,?,?,?,?)");
      $stmt->execute([$fn,$ln,$username,$hash,$is_admin]);

      emit_event('admin_teacher_created', [
        'actor_id' => (int)$u['id'],
        'actor_role' => 'admin',
        'actor_username' => (string)$u['username'],
        'actor_first_name' => (string)$u['first_name'],
        'actor_last_name' => (string)$u['last_name'],
        'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
        'target_user_id' => (int)$pdo->lastInsertId(),
        'target_username' => $username,
        'target_first_name' => $fn,
        'target_last_name' => $ln,
        'target_name' => trim($ln . ', ' . $fn),
        'new' => [
          'first_name' => $fn,
          'last_name' => $ln,
          'username' => $username,
          'is_admin' => $is_admin,
          'password_auto_generated' => true,
        ],
      ]);
      flash_set('success', "Lehrer:in angelegt. Passwort: $pass");
    }
    redirect(url('/admin/teachers.php'));
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)$u['id']) {
      flash_set('error', 'Du kannst dein eigenes Admin-Konto hier nicht löschen.');
      redirect(url('/admin/teachers.php'));
    }

    $stmtOld = $pdo->prepare("SELECT username,first_name,last_name,role,is_admin FROM users WHERE id=? AND role IN ('teacher','admin') LIMIT 1");
    $stmtOld->execute([$id]);
    $old = $stmtOld->fetch() ?: null;

    if (!$old) {
      flash_set('error', 'Konto nicht gefunden.');
      redirect(url('/admin/teachers.php'));
    }

    $deletesAdmin = (string)$old['role'] === 'admin' || (int)($old['is_admin'] ?? 0) === 1;
    if ($deletesAdmin && admin_account_count($pdo) <= 1) {
      flash_set('error', 'Der letzte Admin kann nicht gelöscht werden.');
      redirect(url('/admin/teachers.php'));
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role IN ('teacher','admin')");
    $stmt->execute([$id]);

    emit_event('admin_teacher_deleted', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'admin',
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'target_user_id' => $id,
      'target_username' => (string)($old['username'] ?? ''),
      'target_first_name' => (string)($old['first_name'] ?? ''),
      'target_last_name' => (string)($old['last_name'] ?? ''),
      'target_name' => trim((string)($old['last_name'] ?? '') . ', ' . (string)($old['first_name'] ?? '')),
      'old' => $old,
    ]);

    flash_set('success', 'Konto gelöscht.');
    redirect(url('/admin/teachers.php'));
  }
}

$edit = null;
if (isset($_GET['edit'])) {
  $id = (int)$_GET['edit'];
  $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? AND role IN ('teacher','admin')");
  $stmt->execute([$id]);
  $edit = $stmt->fetch() ?: null;
}

$dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
$orderSql = match ($sort) {
  'first_name' => $dirSql === 'DESC'
    ? 'first_name DESC, last_name ASC'
    : 'first_name ASC, last_name ASC',
  default => $dirSql === 'DESC'
    ? 'last_name DESC, first_name ASC'
    : 'last_name ASC, first_name ASC',
};
$sql = "SELECT id, role, first_name, last_name, username, is_admin FROM users WHERE role IN ('teacher','admin') ORDER BY " . $orderSql;
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Lehrer:innen verwalten</h2>
  <p class="small">Hier werden Lehrkräfte und Admin-Konten angezeigt. Neue Konten werden weiterhin als Lehrer:innen angelegt; Admin-Konten können hier mitgepflegt werden.</p>

  <div class="card">
    <h3><?= $edit ? 'Bearbeiten' : 'Neu anlegen' ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h((string)($edit['id'] ?? 0)) ?>">

      <div class="row">
        <div><label>Vorname</label><input name="first_name" value="<?= h($edit['first_name'] ?? '') ?>" required></div>
        <div><label>Nachname</label><input name="last_name" value="<?= h($edit['last_name'] ?? '') ?>" required></div>
      </div>
      <div class="row">
        <div><label>Username</label><input type="username" name="username" value="<?= h($edit['username'] ?? '') ?>" required></div>
        <div style="flex:0.6;min-width:180px;">
          <label>Admin</label><br>
          <input type="checkbox" name="is_admin" style="width:auto" <?= ((int)($edit['is_admin'] ?? 0)===1 || (string)($edit['role'] ?? '') === 'admin') ? 'checked' : '' ?> <?= (string)($edit['role'] ?? '') === 'admin' ? 'disabled' : '' ?>>
          <?php if ((string)($edit['role'] ?? '') === 'admin'): ?>
            <div class="small">Admin-Konten behalten ihre Adminrechte.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="row">
        <div><label>Passwort (leer = unverändert / Neu = Auto)</label><input name="password" value=""></div>
      </div>

      <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
    </form>
  </div>

  <table class="table">
    <thead><tr><th>Name</th><th>Username</th><th>Typ</th><th>Admin</th><th>Aktion</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['last_name'] . ', ' . $r['first_name']) ?></td>
          <td><?= h($r['username']) ?></td>
          <td><?= (string)$r['role'] === 'admin' ? '<span class="badge ok">Admin-Konto</span>' : '<span class="badge">Lehrer:in</span>' ?></td>
          <td><?= ((int)$r['is_admin']===1 || (string)$r['role'] === 'admin') ? '<span class="badge ok">ja</span>' : '<span class="badge">nein</span>' ?></td>
          <td>
            <a href="<?= h(url('/admin/teachers.php?edit='.(int)$r['id'])) ?>">Bearbeiten</a>
            <?php if ((int)$r['id'] !== (int)$u['id']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Dieses Konto wirklich löschen?')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
                <button class="danger small" style="margin-left:6px;">Löschen</button>
              </form>
            <?php else: ?>
              <span class="small" style="margin-left:6px;">eigenes Konto</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
