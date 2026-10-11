<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';

$u = require_admin();

$pdo = db();

$classFilter = trim((string)($_GET['class'] ?? ''));

$sort = (string)($_GET['sort'] ?? 'class_name'); // class_name | last_name | first_name | username
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$allowedSort = ['class_name', 'last_name', 'first_name', 'username'];
if (!in_array($sort, $allowedSort, true)) $sort = 'class_name';

function qs(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  $s = http_build_query($q);
  return $s ? ('?' . $s) : '';
}

function selected_student_ids(): array {
  $ids = $_POST['selected_ids'] ?? [];
  if (!is_array($ids)) return [];
  $clean = [];
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) $clean[$id] = $id;
  }
  return array_values($clean);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $action = $_POST['action'] ?? '';

  if ($action === 'bulk_set_active' || $action === 'bulk_delete') {
    $ids = selected_student_ids();
    if (!$ids) {
      flash_set('error', 'Bitte zuerst mindestens eine:n Schüler:in markieren.');
      redirect(url('/admin/students.php' . qs()));
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtOld = $pdo->prepare("
      SELECT id, username, class_name, first_name, last_name, is_active
      FROM users
      WHERE role='student' AND id IN ($placeholders)
      ORDER BY class_name, last_name, first_name
    ");
    $stmtOld->execute($ids);
    $selectedRows = $stmtOld->fetchAll();

    if (!$selectedRows) {
      flash_set('error', 'Die markierten Schüler:innen wurden nicht gefunden.');
      redirect(url('/admin/students.php' . qs()));
    }

    $foundIds = array_map(static fn(array $row): int => (int)$row['id'], $selectedRows);
    $foundPlaceholders = implode(',', array_fill(0, count($foundIds), '?'));

    if ($action === 'bulk_delete') {
      $stmt = $pdo->prepare("DELETE FROM users WHERE role='student' AND id IN ($foundPlaceholders)");
      $stmt->execute($foundIds);
      $affected = $stmt->rowCount();

      emit_event('admin_students_bulk_deleted', [
        'actor_id' => (int)$u['id'],
        'actor_role' => 'admin',
        'actor_username' => (string)$u['username'],
        'actor_first_name' => (string)$u['first_name'],
        'actor_last_name' => (string)$u['last_name'],
        'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
        'affected' => $affected,
        'students' => $selectedRows,
      ]);

      flash_set('success', "$affected markierte Schüler:innen gelöscht.");
      redirect(url('/admin/students.php' . qs()));
    }

    $set = (int)($_POST['set'] ?? 1);
    $set = ($set === 1) ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE users SET is_active=? WHERE role='student' AND id IN ($foundPlaceholders)");
    $stmt->execute(array_merge([$set], $foundIds));
    $affected = $stmt->rowCount();

    emit_event('admin_students_bulk_active_set', [
      'actor_id' => (int)$u['id'],
      'actor_role' => 'admin',
      'actor_username' => (string)$u['username'],
      'actor_first_name' => (string)$u['first_name'],
      'actor_last_name' => (string)$u['last_name'],
      'actor_name' => trim((string)$u['last_name'] . ', ' . (string)$u['first_name']),
      'set_active' => $set,
      'affected' => $affected,
      'students' => $selectedRows,
    ]);

    flash_set('success', ($set === 1 ? 'Markierte Schüler:innen auf anwesend gesetzt' : 'Markierte Schüler:innen auf nicht anwesend gesetzt') . ": $affected Datensätze");
    redirect(url('/admin/students.php' . qs()));
  }

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $fn = trim((string)($_POST['first_name'] ?? ''));
    $ln = trim((string)($_POST['last_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $class = trim((string)($_POST['class_name'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if ($fn==='' || $ln==='' || $username==='' || $class==='') {
      flash_set('error', 'Bitte alle Felder (außer Passwort) ausfüllen.');
      redirect(url('/admin/students.php' . qs()));
    }

  if ($pass !== '' && mb_strlen($pass, '8bit') < 8) {
    flash_set('error', 'Passwort muss mindestens 8 Zeichen haben.');
    redirect(url('/admin/students.php' . qs()));
  }

    if ($id > 0) {
      // Load old values for audit
      $stmtOld = $pdo->prepare("SELECT first_name,last_name,username,class_name FROM users WHERE id=? AND role='student' LIMIT 1");
      $stmtOld->execute([$id]);
      $old = $stmtOld->fetch() ?: null;

      if ($pass !== '') {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=?, class_name=?, password_hash=? WHERE id=? AND role='student'");
        $stmt->execute([$fn,$ln,$username,$class,$hash,$id]);
      } else {
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=?, class_name=? WHERE id=? AND role='student'");
        $stmt->execute([$fn,$ln,$username,$class,$id]);
      }
      flash_set('success', 'Schüler:in gespeichert.');

      emit_event('admin_student_updated', [
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
        'target_class' => $class,
        'old' => $old,
        'new' => [
          'first_name' => $fn,
          'last_name' => $ln,
          'username' => $username,
          'class_name' => $class,
          'password_changed' => ($pass !== ''),
        ],
      ]);
    } else {
      if ($pass === '') $pass = bin2hex(random_bytes(4));
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO users (role, first_name, last_name, username, password_hash, class_name) VALUES ('student',?,?,?,?,?)");
      $stmt->execute([$fn,$ln,$username,$hash,$class]);

      emit_event('admin_student_created', [
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
        'target_class' => $class,
        'new' => [
          'first_name' => $fn,
          'last_name' => $ln,
          'username' => $username,
          'class_name' => $class,
          'password_auto_generated' => true,
        ],
      ]);
      flash_set('success', "Schüler:in angelegt. Passwort: $pass");
    }
    redirect(url('/admin/students.php' . qs()));
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    // Load old values for audit (best effort)
    $stmtOld = $pdo->prepare("SELECT username,class_name,first_name,last_name FROM users WHERE id=? AND role='student' LIMIT 1");
    $stmtOld->execute([$id]);
    $old = $stmtOld->fetch() ?: null;

    $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role='student'");
    $stmt->execute([$id]);

    emit_event('admin_student_deleted', [
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
      'target_class' => (string)($old['class_name'] ?? ''),
      'old' => $old,
    ]);

    flash_set('success', 'Schüler:in gelöscht.');
    redirect(url('/admin/students.php' . qs()));
  }

if ($action === 'toggle_active') {
  $id = (int)($_POST['id'] ?? 0);

  $stmtOld = $pdo->prepare("SELECT username,class_name,is_active FROM users WHERE id=? AND role='student' LIMIT 1");
  $stmtOld->execute([$id]);
  $old = $stmtOld->fetch() ?: null;

  $stmt = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=? AND role='student'");
  $stmt->execute([$id]);

  // Determine new active state
  $stmtNew = $pdo->prepare("SELECT is_active FROM users WHERE id=? LIMIT 1");
  $stmtNew->execute([$id]);
  $newActive = (int)($stmtNew->fetchColumn() ?? 0);

  emit_event('admin_student_active_toggled', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'admin',
    'target_user_id' => $id,
    'target_username' => (string)($old['username'] ?? ''),
    'class' => (string)($old['class_name'] ?? ''),
    'old_active' => (int)($old['is_active'] ?? 0),
    'new_active' => $newActive,
  ]);

  flash_set('success', $newActive === 1 ? 'Schüler:in ist wieder anwesend.' : 'Schüler:in wurde auf nicht anwesend gesetzt.');
  redirect(url('/admin/students.php' . qs()));
}
if ($action === 'class_set_active') {
  $class = trim((string)($_POST['class_name'] ?? ''));
  $set = (int)($_POST['set'] ?? 1);
  $set = ($set === 1) ? 1 : 0;

  if ($class === '') {
    flash_set('error', 'Bitte zuerst eine Klasse filtern.');
    redirect(url('/admin/students.php' . qs()));
  }

  $stmt = $pdo->prepare("UPDATE users SET is_active=? WHERE role='student' AND class_name=?");
  $stmt->execute([$set, $class]);
  $n = $stmt->rowCount();

  emit_event('admin_class_active_set', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'admin',
    'class' => $class,
    'set_active' => $set,
    'affected' => $n,
  ]);

  flash_set('success', ($set===1 ? 'Klasse auf anwesend gesetzt' : 'Klasse auf nicht anwesend gesetzt') . ": $class ($n Datensätze)");
  redirect(url('/admin/students.php' . qs(['class'=>$class])));
}



}

$edit = null;
if (isset($_GET['edit'])) {
  $id = (int)$_GET['edit'];
  $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='student'");
  $stmt->execute([$id]);
  $edit = $stmt->fetch() ?: null;
}

$dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
$orderSql = match ($sort) {
  'last_name' => $dirSql === 'DESC'
    ? 'last_name DESC, first_name ASC, class_name ASC'
    : 'last_name ASC, first_name ASC, class_name ASC',
  'first_name' => $dirSql === 'DESC'
    ? 'first_name DESC, last_name ASC, class_name ASC'
    : 'first_name ASC, last_name ASC, class_name ASC',
  'username' => $dirSql === 'DESC'
    ? 'username DESC, last_name ASC, first_name ASC'
    : 'username ASC, last_name ASC, first_name ASC',
  default => $dirSql === 'DESC'
    ? 'class_name DESC, last_name ASC, first_name ASC'
    : 'class_name ASC, last_name ASC, first_name ASC',
};

if ($classFilter !== '') {
  $sql = "SELECT id, first_name, last_name, username, class_name, is_active, must_change_password FROM users WHERE role='student' AND class_name=? ORDER BY " . $orderSql;
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$classFilter]);
  $rows = $stmt->fetchAll();
} else {
  $sql = "SELECT id, first_name, last_name, username, class_name, is_active, must_change_password FROM users WHERE role='student' ORDER BY " . $orderSql;
  $stmt = $pdo->prepare($sql);
  $stmt->execute();
  $rows = $stmt->fetchAll();
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Schüler:innen verwalten</h2>

<div class="card">
  <h3>Filter</h3>
  <?php
    $classList = $pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>'' ORDER BY class_name")->fetchAll();
  ?>
  <form method="get" class="row" style="align-items:end;">
    <div style="max-width:260px;">
      <label>Klasse</label>
      <select name="class" onchange="this.form.submit()">
        <option value="">Alle Klassen</option>
        <?php foreach ($classList as $c): $cn=$c['class_name']; ?>
          <option value="<?= h($cn) ?>" <?= $classFilter===$cn?'selected':'' ?>><?= h($cn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- keep teacher sort params -->
    <input type="hidden" name="sort" value="<?= h($sort) ?>">
    <input type="hidden" name="dir" value="<?= h($dir) ?>">

    <div>
      <button type="submit">Anwenden</button>
      <?php if ($classFilter !== ''): ?>
        <a class="button" href="<?= h(url('/admin/students.php' . qs(['class'=>null]))) ?>" style="margin-left:8px;">Filter löschen</a>
      <?php endif; ?>
    </div>
  </form>
<?php if ($classFilter !== ''): ?>
  <div class="row" style="margin-top:14px; align-items:center;">
    <div class="small" style="opacity:.85;">Klassenaktion für <strong><?= h($classFilter) ?></strong>:</div>

    <form method="post" action="<?= h(url('/admin/students.php' . qs())) ?>" style="display:inline; margin-left:10px;"
          onsubmit="return confirm('Wirklich alle Schüler:innen der gefilterten Klasse auf nicht anwesend setzen? Sie können sich dann nicht mehr anmelden.')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="class_set_active">
      <input type="hidden" name="class_name" value="<?= h($classFilter) ?>">
      <input type="hidden" name="set" value="0">
      <button class="small class-status-action class-status-action--inactive">Ganze Klasse nicht anwesend</button>
    </form>

    <form method="post" action="<?= h(url('/admin/students.php' . qs())) ?>" style="display:inline; margin-left:8px;"
          onsubmit="return confirm('Wirklich alle Schüler:innen der gefilterten Klasse auf anwesend setzen?')">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="class_set_active">
      <input type="hidden" name="class_name" value="<?= h($classFilter) ?>">
      <input type="hidden" name="set" value="1">
      <button class="small class-status-action class-status-action--active">Ganze Klasse anwesend</button>
    </form>
  </div>
<?php endif; ?>


</div>


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
        <div><label>Klasse</label><input name="class_name" value="<?= h($edit['class_name'] ?? '') ?>" required></div>
      </div>
      <div class="row">
        <div><label>Passwort (leer = unverändert / Neu = Auto)</label><input name="password" value=""></div>
      </div>

      <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
    </form>
  </div>

  <h3>Liste</h3>
  <form method="post" id="bulkStudentsForm" action="<?= h(url('/admin/students.php' . qs())) ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="set" id="bulkSetValue" value="">
  </form>
  <div class="bulk-actions" aria-label="Sammelaktionen für markierte Schüler:innen">
    <span class="bulk-actions__count"><strong id="selectedStudentCount">0</strong> markiert</span>
    <button class="small class-status-action class-status-action--active" type="submit" form="bulkStudentsForm" name="action" value="bulk_set_active" onclick="document.getElementById('bulkSetValue').value='1'; return confirmBulkStudents('Markierte Schüler:innen auf anwesend setzen?');">Markierte anwesend</button>
    <button class="small class-status-action class-status-action--inactive" type="submit" form="bulkStudentsForm" name="action" value="bulk_set_active" onclick="document.getElementById('bulkSetValue').value='0'; return confirmBulkStudents('Markierte Schüler:innen auf nicht anwesend setzen?');">Markierte abwesend</button>
    <button class="small" type="button" id="editSelectedStudent">Markierte bearbeiten</button>
    <button class="danger small" type="submit" form="bulkStudentsForm" name="action" value="bulk_delete" onclick="return confirmBulkStudents('Markierte Schüler:innen wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">Markierte löschen</button>
  </div>
  <table class="table">
    <thead><tr><th><input type="checkbox" id="selectAllStudents" aria-label="Alle Schüler:innen markieren"></th><th>Nr.</th><th><a href="<?= h(url('/admin/students.php' . qs(['sort'=>'class_name','dir'=>(($sort==='class_name' && $dir==='asc')?'desc':'asc')]))) ?>">Klasse <?= ($sort==='class_name' ? ($dir==='asc'?'▲':'▼') : '') ?></a></th><th><a href="<?= h(url('/admin/students.php' . qs(['sort'=>'last_name','dir'=>(($sort==='last_name' && $dir==='asc')?'desc':'asc')]))) ?>">Nachname <?= ($sort==='last_name' ? ($dir==='asc'?'▲':'▼') : '') ?></a></th><th><a href="<?= h(url('/admin/students.php' . qs(['sort'=>'first_name','dir'=>(($sort==='first_name' && $dir==='asc')?'desc':'asc')]))) ?>">Vorname <?= ($sort==='first_name' ? ($dir==='asc'?'▲':'▼') : '') ?></a></th><th><a href="<?= h(url('/admin/students.php' . qs(['sort'=>'username','dir'=>(($sort==='username' && $dir==='asc')?'desc':'asc')]))) ?>">Username <?= ($sort==='username' ? ($dir==='asc'?'▲':'▼') : '') ?></a></th><th>Anwesenheit</th><th>Status</th><th>Aktion</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr data-student-row>
          <td data-label="Auswahl">
            <input type="checkbox" name="selected_ids[]" value="<?= (int)$r['id'] ?>" form="bulkStudentsForm" class="student-select" aria-label="<?= h($r['last_name'] . ', ' . $r['first_name']) ?> markieren">
          </td>
          <td data-label="Nr."><?= $i + 1 ?></td>
          <td><?= h($r['class_name']) ?></td>
          <td><?= h($r['last_name']) ?></td>
          <td><?= h($r['first_name']) ?></td>
          <td><?= h($r['username']) ?></td>

          <td>
            <form method="post" style="display:inline" <?= ((int)$r['is_active'] === 1 ? "onsubmit=\"return confirm('Diese Person wirklich auf nicht anwesend setzen?')\"" : "") ?>>
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
              <?php if ((int)$r['is_active'] === 1): ?>
                <button class="small class-status-action class-status-action--present" title="Auf abwesend setzen">anwesend</button>
              <?php else: ?>
                <button class="small class-status-action class-status-action--absent" title="Auf anwesend setzen">abwesend</button>
              <?php endif; ?>
            </form>
          </td>

          <td>
            <?php if ((int)($r['must_change_password'] ?? 0) === 1): ?>
              <span class="badge warn">muss Passwort ändern</span>
            <?php else: ?>
              <span class="badge ok">ok</span>
            <?php endif; ?>
          </td>

          <td>
            <a href="<?= h(url('/admin/students.php' . qs(['edit'=>(int)$r['id']]))) ?>">Bearbeiten</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen?')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string)$r['id']) ?>">
              <button class="danger small" style="margin-left:6px;">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
(() => {
  const checkboxes = Array.from(document.querySelectorAll('.student-select'));
  const selectAll = document.getElementById('selectAllStudents');
  const count = document.getElementById('selectedStudentCount');
  const editButton = document.getElementById('editSelectedStudent');
  const editUrlBase = <?= json_encode(url('/admin/students.php' . qs(['edit' => '__ID__']))) ?>;

  function selectedBoxes() {
    return checkboxes.filter((checkbox) => checkbox.checked);
  }

  function syncSelection() {
    const selected = selectedBoxes();
    if (count) count.textContent = String(selected.length);
    checkboxes.forEach((checkbox) => {
      const row = checkbox.closest('[data-student-row]');
      if (row) row.classList.toggle('is-selected', checkbox.checked);
    });
    if (selectAll) {
      selectAll.checked = selected.length > 0 && selected.length === checkboxes.length;
      selectAll.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
    }
  }

  window.confirmBulkStudents = function(message) {
    const selected = selectedBoxes().length;
    if (selected === 0) {
      alert('Bitte zuerst mindestens eine:n Schüler:in markieren.');
      return false;
    }
    return confirm(message + '\n\nMarkiert: ' + selected);
  };

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      checkboxes.forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      syncSelection();
    });
  }

  checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncSelection));

  if (editButton) {
    editButton.addEventListener('click', () => {
      const selected = selectedBoxes();
      if (selected.length !== 1) {
        alert('Bitte genau eine:n Schüler:in markieren, um zu bearbeiten.');
        return;
      }
      window.location.href = editUrlBase.replace('__ID__', encodeURIComponent(selected[0].value));
    });
  }

  syncSelection();
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
