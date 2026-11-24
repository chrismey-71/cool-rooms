<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/daily_reset.php';

$u = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  reset_all_assignments([
    'id' => (int)$u['id'],
    'role' => 'admin',
    'name' => trim((string)($u['last_name'] ?? '') . ' ' . (string)($u['first_name'] ?? '')),
    'username' => (string)($u['username'] ?? ''),
  ]);
  flash_set('success', 'Alle Raumzuordnungen und verantwortlichen Klassen wurden gelöscht. Alle Schüler:innen sind wieder anwesend und nicht eingebucht.');
  redirect(url('/admin/reset.php'));
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Reset am Ende des COOL-Tages</h2>
  <p>Damit werden <strong>alle aktuellen Raumzuordnungen</strong> und die <strong>verantwortlichen Klassen der Lehrkräfte</strong> gelöscht. Zusätzlich werden alle Schüler:innen wieder auf <strong>anwesend</strong> gesetzt.</p>
  <form method="post" onsubmit="return confirm('Wirklich ALLE Raumzuordnungen und verantwortlichen Klassen löschen und alle Schüler:innen auf anwesend setzen?')">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <button class="danger" type="submit">Jetzt Raumzuordnungen und Klassen zurücksetzen</button>
  </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
