<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/events.php';
require_once __DIR__ . '/../lib/kiosk_access.php';

$u = require_admin();
$pdo = db();
kiosk_ensure_secure_storage($pdo);

function get_setting(string $k, string $default=''): string {
  $stmt = db()->prepare("SELECT v FROM app_settings WHERE k=?");
  $stmt->execute([$k]);
  $v = $stmt->fetchColumn();
  return ($v === false) ? $default : (string)$v;
}
function set_setting(string $k, string $v): void {
  $stmt = db()->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $stmt->execute([$k,$v]);
}
function valid_date_or_default(string $value, string $default): string {
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return ($dt && $dt->format('Y-m-d') === $value) ? $value : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  // capture old values for audit
  $oldEnabled = get_setting('kiosk_enabled', '0');
  $oldExpires = get_setting('kiosk_expires', '');
  $oldValidFrom = get_setting('kiosk_valid_from', $oldExpires);
  $oldValidTo = get_setting('kiosk_valid_to', $oldExpires);
  $oldCode = kiosk_school_code($pdo);
  $oldHasCode = $oldCode !== '' ? 1 : 0;

  $today = (new DateTimeImmutable('now'))->format('Y-m-d');
  $enabled = isset($_POST['kiosk_enabled']) ? '1' : '0';
  $code = trim((string)($_POST['kiosk_day_code'] ?? ''));
  $validFrom = valid_date_or_default(trim((string)($_POST['kiosk_valid_from'] ?? '')), $today);
  $validTo = valid_date_or_default(trim((string)($_POST['kiosk_valid_to'] ?? '')), $validFrom);

  if ($validTo < $validFrom) {
    flash_set('error', 'Das Bis-Datum darf nicht vor dem Von-Datum liegen.');
    redirect(url('/admin/kiosk.php'));
  }

  set_setting('kiosk_enabled', $enabled);
  if (!hash_equals($oldCode, $code)) {
    kiosk_set_school_code($pdo, $code);
  }
  set_setting('kiosk_valid_from', $validFrom);
  set_setting('kiosk_valid_to', $validTo);
  set_setting('kiosk_expires', $validTo);

  emit_event('admin_kiosk_school_pin_change', [
    'actor_id' => (int)$u['id'],
    'actor_role' => 'admin',
    'enabled_old' => $oldEnabled,
    'enabled_new' => $enabled,
    'expires_old' => $oldExpires,
    'expires_new' => $validTo,
    'valid_from_old' => $oldValidFrom,
    'valid_from_new' => $validFrom,
    'valid_to_old' => $oldValidTo,
    'valid_to_new' => $validTo,
    // do NOT store kiosk code
    'code_was_set_old' => $oldHasCode,
    'code_is_set_new' => ($code !== '') ? 1 : 0,
  ]);

  flash_set('success', 'Kiosk-Einstellungen gespeichert.');
  redirect(url('/admin/kiosk.php'));
}

$enabled = get_setting('kiosk_enabled', '0');
$code = kiosk_school_code($pdo);
$expires = get_setting('kiosk_expires', '');
$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$validFrom = get_setting('kiosk_valid_from', $expires ?: $today);
$validTo = get_setting('kiosk_valid_to', $expires ?: $today);
$validFrom = valid_date_or_default($validFrom, $today);
$validTo = valid_date_or_default($validTo, $validFrom);

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Kiosk-Login verwalten</h2>

  <div class="card">
    <h3>Was macht der Kioskmodus?</h3>
    <p>
      Der Kioskmodus ist für Geräte gedacht, die gemeinsam genutzt werden, zum Beispiel ein Computer in jedem Klassenraum.
      Schüler:innen melden sich dort nicht mit ihrem persönlichen Passwort an, sondern mit einem Kiosk-Kennwort und wählen anschließend Klasse, Namen und Raum.
    </p>
    <p class="small">
      Für Lehrkräfte ist der Kioskmodus hilfreich, wenn eine Klasse gesammelt an einem Raumgerät arbeitet: Die Lehrkraft kann den Zugang vorbereiten,
      ohne einzelne Schülerpasswörter auszugeben. Der Admin-Code öffnet die Auswahl für alle Klassen; Klassen-Kiosk-PINs beschränken die Auswahl auf eine Klasse.
      Der Zeitraum legt fest, an welchen Tagen der Kiosk überhaupt Kennwörter akzeptiert.
    </p>
  </div>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="row">
        <div style="flex:0.6;min-width:180px;">
          <label>Kiosk aktiv</label><br>
          <input type="checkbox" name="kiosk_enabled" style="width:auto" <?= $enabled==='1'?'checked':'' ?>>
        </div>
        <div>
          <label>Gültig von</label>
          <input type="date" name="kiosk_valid_from" value="<?= h($validFrom) ?>">
        </div>
        <div>
          <label>Gültig bis</label>
          <input type="date" name="kiosk_valid_to" value="<?= h($validTo) ?>">
        </div>
      </div>

      <div class="row">
        <div>
          <label>Kiosk-Code</label>
          <input name="kiosk_day_code" value="<?= h($code) ?>" placeholder="z.B. COOL-1234">
          <div class="small">Dieser Code gilt im gewählten Zeitraum für den schulweiten Kioskzugang. Bitte nicht zu kurz wählen und bei Bedarf regelmäßig wechseln.</div>
        </div>
      </div>

      <p style="margin-top:12px;"><button type="submit">Speichern</button></p>
    </form>
  </div>

  <div class="card">
    <h3>Kiosk-Link</h3>
    <p><span class="badge"><?= h(url('/kiosk.php')) ?></span></p>
    <p class="small">Diesen Link kannst du auf Raumcomputern als Startseite oder Lesezeichen speichern. Optional kann daraus ein QR-Code erzeugt werden.</p>
  </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
