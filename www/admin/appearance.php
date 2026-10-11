<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/branding.php';

$u = require_admin();
$pdo = db();
$defaults = branding_defaults();
$colorFields = branding_color_fields();

function appearance_remove_custom_logo(string $logoPath): void {
  if (!preg_match('#^/assets/branding/logo-[a-f0-9]{16}\.(?:png|jpe?g|webp)$#i', $logoPath)) {
    return;
  }
  $fullPath = dirname(__DIR__) . $logoPath;
  if (is_file($fullPath)) {
    @unlink($fullPath);
  }
}

function appearance_uploaded_logo(array $file): string {
  $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($error === UPLOAD_ERR_NO_FILE) {
    return '';
  }
  if ($error !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Das Logo konnte nicht vollständig hochgeladen werden.');
  }
  if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
    throw new RuntimeException('Das Logo darf höchstens 2 MB groß sein.');
  }

  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException('Ungültiger Logo-Upload.');
  }
  $info = @getimagesize($tmp);
  $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
  $extensions = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
  ];
  if (!isset($extensions[$mime])) {
    throw new RuntimeException('Erlaubt sind PNG-, JPG- und WebP-Dateien.');
  }
  $width = (int)($info[0] ?? 0);
  $height = (int)($info[1] ?? 0);
  if ($width < 80 || $height < 40 || $width > 4000 || $height > 4000) {
    throw new RuntimeException('Das Logo muss zwischen 80 × 40 und 4000 × 4000 Pixel groß sein.');
  }

  $directory = dirname(__DIR__) . '/assets/branding';
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    throw new RuntimeException('Der Logo-Ordner konnte nicht angelegt werden.');
  }
  $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
  if (!move_uploaded_file($tmp, $directory . '/' . $filename)) {
    throw new RuntimeException('Das Logo konnte nicht gespeichert werden.');
  }
  return '/assets/branding/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'save');
  $current = branding_settings($pdo);

  try {
    if ($action === 'reset') {
      appearance_remove_custom_logo((string)$current['brand_logo']);
      foreach ($defaults as $key => $value) {
        branding_save_setting($pdo, $key, (string)$value);
      }
      flash_set('success', 'Erscheinungsbild auf die Standardwerte zurückgesetzt.');
      redirect(url('/admin/appearance.php'));
    }

    $title = trim((string)($_POST['brand_title'] ?? ''));
    $subtitle = trim((string)($_POST['brand_subtitle'] ?? ''));
    if ($title === '') {
      throw new RuntimeException('Bitte einen Titel eingeben.');
    }
    if (mb_strlen($title, 'UTF-8') > 80 || mb_strlen($subtitle, 'UTF-8') > 120) {
      throw new RuntimeException('Titel oder Untertitel ist zu lang.');
    }

    $logoPath = (string)$current['brand_logo'];
    if (!empty($_POST['use_default_logo'])) {
      appearance_remove_custom_logo($logoPath);
      $logoPath = (string)$defaults['brand_logo'];
    } elseif (!empty($_FILES['brand_logo'])) {
      $uploaded = appearance_uploaded_logo($_FILES['brand_logo']);
      if ($uploaded !== '') {
        appearance_remove_custom_logo($logoPath);
        $logoPath = $uploaded;
      }
    }

    branding_save_setting($pdo, 'brand_title', $title);
    branding_save_setting($pdo, 'brand_subtitle', $subtitle);
    branding_save_setting($pdo, 'brand_logo', $logoPath);
    foreach ($colorFields as $key => $field) {
      $color = branding_valid_color((string)($_POST[$key] ?? ''), $field['default']);
      branding_save_setting($pdo, $key, $color);
    }

    flash_set('success', 'Erscheinungsbild gespeichert.');
    redirect(url('/admin/appearance.php'));
  } catch (Throwable $e) {
    flash_set('error', $e->getMessage());
    redirect(url('/admin/appearance.php'));
  }
}

$branding = branding_settings($pdo);
$groupedFields = [];
foreach ($colorFields as $key => $field) {
  $groupedFields[$field['group']][$key] = $field;
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card appearance-page">
  <h2>Erscheinungsbild</h2>
  <p class="small">Hier werden ausschließlich Bezeichnungen, Logo und Farben verändert. Raumzuordnungen, Benutzerkonten, Kiosk und alle Abläufe bleiben unverändert.</p>

  <form method="post" enctype="multipart/form-data" data-appearance-form>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">

    <div class="card">
      <h3>Titel, Schule und Logo</h3>
      <div class="row">
        <div>
          <label>Titel</label>
          <input name="brand_title" maxlength="80" required value="<?= h((string)$branding['brand_title']) ?>">
          <div class="small">Wird im Kopfbereich, Browser-Tab und als App-Name verwendet.</div>
        </div>
        <div>
          <label>Untertitel</label>
          <input name="brand_subtitle" maxlength="120" value="<?= h((string)$branding['brand_subtitle']) ?>">
          <div class="small">Wird unter dem Titel im Kopfbereich angezeigt.</div>
        </div>
      </div>
      <div class="appearance-logo-row">
        <div class="appearance-logo-preview"><img src="<?= h(url((string)$branding['brand_logo'])) ?>" alt="Aktuelles Logo"></div>
        <div>
          <label>Eigenes Logo hochladen</label>
          <input type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp">
          <div class="small">PNG, JPG oder WebP, höchstens 2 MB. Ein breites Logo mit transparentem Hintergrund eignet sich am besten.</div>
          <label class="appearance-check"><input type="checkbox" name="use_default_logo" value="1"> Standardlogo wieder verwenden</label>
        </div>
      </div>
    </div>

    <?php foreach ($groupedFields as $group => $fields): ?>
      <div class="card appearance-color-group">
        <h3><?= h($group) ?></h3>
        <div class="appearance-color-grid">
          <?php foreach ($fields as $key => $field): ?>
            <label class="appearance-color-field">
              <span><?= h($field['label']) ?></span>
              <span class="appearance-color-control">
                <input type="color" name="<?= h($key) ?>" value="<?= h((string)$branding[$key]) ?>" data-theme-variable="<?= h('--' . str_replace('_', '-', $key)) ?>">
                <code data-color-value><?= h((string)$branding[$key]) ?></code>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="card appearance-live-preview">
      <h3>Vorschau</h3>
      <div class="appearance-preview-header">Navigation und Hauptaktionen</div>
      <div class="appearance-preview-grid">
        <span style="--preview:var(--theme-admin-rooms)">Raumsteuerung</span>
        <span style="--preview:var(--theme-admin-classes)">Klassenorganisation</span>
        <span style="--preview:var(--theme-admin-overview)">Lehrkräfte</span>
        <span style="--preview:var(--theme-teacher-day)">Aktueller Tag</span>
        <span style="--preview:var(--theme-teacher-tools)">Lehrer-Werkzeuge</span>
        <span style="--preview:var(--theme-student-current)">Aktueller Raum</span>
        <span style="--preview:var(--theme-student-classroom)">Klassenraum</span>
        <span style="--preview:var(--theme-warning)">Kiosk</span>
      </div>
    </div>

    <p class="appearance-actions"><button type="submit">Erscheinungsbild speichern</button></p>
  </form>

  <form method="post" onsubmit="return confirm('Wirklich Titel, Untertitel, Logo und alle Farben auf die Standardwerte zurücksetzen?')">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="reset">
    <button class="secondary" type="submit">Alle Standardwerte wiederherstellen</button>
  </form>
</div>
<script>
(function(){
  var form = document.querySelector('[data-appearance-form]');
  if (!form) return;
  form.querySelectorAll('input[type="color"][data-theme-variable]').forEach(function(input){
    input.addEventListener('input', function(){
      document.documentElement.style.setProperty(input.dataset.themeVariable, input.value);
      var output = input.parentElement.querySelector('[data-color-value]');
      if (output) output.textContent = input.value.toUpperCase();
    });
  });
})();
</script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
