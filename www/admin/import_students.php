<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/class_groups.php';

$u = require_admin();
$pdo = db();
class_groups_schema($pdo);

$createdCredentials = [];

function rand_pass(): string {
  return rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Aa'), '=');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    flash_set('error', 'CSV Upload fehlgeschlagen.');
    redirect(url('/admin/import_students.php'));
  }

  $tmp = $_FILES['csv']['tmp_name'];
  $fh = fopen($tmp, 'r');
  if (!$fh) {
    flash_set('error', 'CSV konnte nicht gelesen werden.');
    redirect(url('/admin/import_students.php'));
  }

  // --- Robust CSV header parsing (delimiter + BOM + header synonyms) ---
  // Read first line (header)
  $headerRaw = fgetcsv($fh, 0, ';', '"', '\\');
  $delimiter = ';';

  // Fallback to comma if it looks like a single column
  if (!$headerRaw || count($headerRaw) < 2) {
    rewind($fh);
    $headerRaw = fgetcsv($fh, 0, ',', '"', '\\');
    $delimiter = ',';
  }

  $cleanCell = function(string $s): string {
    if (!mb_check_encoding($s, 'UTF-8')) {
      $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
    }

    // Excel exports can prefix the first header with an invisible UTF-8 BOM.
    $s = preg_replace('/^\x{FEFF}/u', '', $s) ?? $s;
    $s = preg_replace("/^\xEF\xBB\xBF/", '', $s) ?? $s;

    return trim($s);
  };

  // Normalize header cells for matching, but keep imported data as entered.
  $norm = function(string $s) use ($cleanCell): string {
    $s = $cleanCell($s);
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(['"', "'"], '', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s;
  };

  $header2 = array_map($norm, $headerRaw ?: []);

  // Accept common header variants (Excel exports often differ)
$variants = [
  'vorname' => ['vorname','first name','firstname','given name','givenname'],
  'nachname' => ['nachname','last name','lastname','surname','family name','familyname'],
  'username' => ['username','user','login','benutzer','benutzername','mailadresse','e-mail','email','mail','emailadresse','e mail'],
  'klasse' => ['klasse','class','classname','class name','gruppe','group'],
];


  $map = ['vorname'=>false,'nachname'=>false,'username'=>false,'klasse'=>false];

  foreach ($header2 as $i => $hcell) {
    foreach ($variants as $key => $alts) {
      if ($map[$key] !== false) continue;
      if (in_array($hcell, $alts, true)) {
        $map[$key] = $i;
      }
    }
  }

  // If any required columns are missing, show what we detected
  foreach ($map as $k => $idx) {
    if ($idx === false) {
      fclose($fh);
      $det = implode(' | ', array_filter($header2));
      flash_set('error', 'CSV Kopfzeile fehlt/ist anders benannt. Erwartet: Vorname;Nachname;Mailadresse;Klasse. '
        . 'Gefunden: ' . $det . ' (Trennzeichen: ' . ($delimiter===';'?'Semikolon':'Komma') . '). '
        . 'Tipp: In Excel als "CSV UTF-8 (Trennzeichen-getrennt)" speichern oder Header exakt benennen.');
      redirect(url('/admin/import_students.php'));
    }
  }

  $pdo->beginTransaction();
  try {
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
      $fn = $cleanCell((string)($row[$map['vorname']] ?? ''));
      $ln = $cleanCell((string)($row[$map['nachname']] ?? ''));
      $username = $norm((string)($row[$map['username']] ?? ''));
      $class = $cleanCell((string)($row[$map['klasse']] ?? ''));

      if ($fn==='' || $ln==='' || $username==='' || $class==='') continue;

      $stmt = $pdo->prepare("SELECT id, role, class_name FROM users WHERE username=? LIMIT 1 FOR UPDATE");
      $stmt->execute([$username]);
      $existing = $stmt->fetch();

      if ($existing) {
        if ((string)$existing['role'] !== 'student') {
          throw new DomainException('Der Benutzername „' . $username . '“ gehört bereits zu einem Lehrkraft- oder Administrationskonto. Der gesamte Import wurde abgebrochen.');
        }
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, class_name=?, is_active=1 WHERE id=? AND role='student'");
        $stmt->execute([$fn,$ln,$class,(int)$existing['id']]);
        if((string)$existing['class_name']!==$class){$stmt=$pdo->prepare('DELETE FROM student_class_groups WHERE student_id=?');$stmt->execute([(int)$existing['id']]);}
      } else {
        $pw = rand_pass();
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role, first_name, last_name, username, password_hash, class_name) VALUES ('student',?,?,?,?,?)");
        $stmt->execute([$fn,$ln,$username,$hash,$class]);
        $createdCredentials[] = ['username'=>$username,'password'=>$pw,'name'=>"$fn $ln",'class'=>$class];
      }
    }
    $pdo->commit();
  } catch (DomainException $e) {
    $pdo->rollBack();
    fclose($fh);
    flash_set('error', $e->getMessage());
    redirect(url('/admin/import_students.php'));
  } catch (Throwable $e) {
    $pdo->rollBack();
    fclose($fh);
    error_log('[COOL Rooms CSV import] ' . $e->getMessage());
    flash_set('error', 'Der Import konnte nicht abgeschlossen werden. Technische Details wurden protokolliert.');
    redirect(url('/admin/import_students.php'));
  }
  fclose($fh);

  flash_set('success', 'Import abgeschlossen. Neu angelegte Accounts: ' . count($createdCredentials));
}

require __DIR__ . '/../partials/header.php';
?>
<div class="card">
  <h2>Schüler:innen importieren (CSV)</h2>
  <p>Format: <span class="badge">Vorname;Nachname;Mailadresse;Klasse</span> (Semikolon oder Komma)</p>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <p style="margin-top:12px;"><button type="submit">Import starten</button></p>
  </form>

  <?php if (!empty($createdCredentials)): ?>
    <div class="card">
      <h3>Neu angelegte Logins</h3>
      <p class="small">Diese Passwörter werden nur jetzt angezeigt.</p>
      <table class="table">
        <thead><tr><th>Nr.</th><th>Klasse</th><th>Name</th><th>Username</th><th>Passwort</th></tr></thead>
        <tbody>
          <?php foreach ($createdCredentials as $i => $c): ?>
            <tr>
              <td data-label="Nr."><?= $i + 1 ?></td>
              <td><?= h($c['class']) ?></td>
              <td><?= h($c['name']) ?></td>
              <td><?= h($c['username']) ?></td>
              <td><span class="badge"><?= h($c['password']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
