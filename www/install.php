<?php
declare(strict_types=1);

ini_set('display_errors', '0');
session_start();
date_default_timezone_set('Europe/Vienna');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$configPath = __DIR__ . '/config.php';
if (empty($_SESSION['install_csrf'])) {
  $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$err = '';
$ok = false;
$blockedMessage = '';
$setupConfig = null;
$setupConfigPath = '';
$installMarkerPath = '';
$setupConfigMissing = false;
$setupConfigUsesOverride = false;
$expectedSetupFilename = '';

function install_log_error(Throwable $e): string {
  $reference = bin2hex(random_bytes(6));
  error_log('[COOL Rooms installer][' . $reference . '] ' . get_class($e) . ': ' . $e->getMessage());
  return $reference;
}

function install_is_absolute_path(string $path): bool {
  return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1);
}

function install_path_is_inside(string $path, string $directory): bool {
  $path = rtrim(str_replace('\\', '/', $path), '/');
  $directory = rtrim(str_replace('\\', '/', $directory), '/');
  if (DIRECTORY_SEPARATOR === '\\') {
    $path = strtolower($path);
    $directory = strtolower($directory);
  }
  return $path === $directory || str_starts_with($path . '/', $directory . '/');
}

function install_instance_id(): string {
  $publicPath = realpath(__DIR__);
  if ($publicPath === false) {
    throw new RuntimeException('Application path could not be resolved.');
  }
  return substr(hash('sha256', str_replace('\\', '/', $publicPath)), 0, 12);
}

function install_setup_config_path(): string {
  $override = trim((string)(getenv('COOL_ROOMS_INSTALL_CONFIG') ?: ''));
  if ($override !== '') {
    if (!install_is_absolute_path($override)) {
      throw new RuntimeException('COOL_ROOMS_INSTALL_CONFIG must be an absolute path.');
    }
    return $override;
  }

  $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
  if ($documentRoot === false) {
    throw new RuntimeException('Document root could not be resolved.');
  }
  return dirname($documentRoot) . DIRECTORY_SEPARATOR . 'install-secret-' . install_instance_id() . '.php';
}

function install_load_setup_config(string $path): array {
  $realPath = realpath($path);
  if ($realPath === false || !is_file($realPath)) {
    throw new RuntimeException('Setup configuration is missing at ' . $path . '.');
  }

  $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
  $publicPath = realpath(__DIR__);
  if (
    $documentRoot === false ||
    $publicPath === false ||
    install_path_is_inside($path, $documentRoot) ||
    install_path_is_inside($path, $publicPath) ||
    install_path_is_inside($realPath, $documentRoot) ||
    install_path_is_inside($realPath, $publicPath)
  ) {
    throw new RuntimeException('Setup configuration must be outside all public application paths.');
  }

  $config = require $realPath;
  if (!is_array($config)) {
    throw new RuntimeException('Setup configuration must return an array.');
  }

  $token = (string)($config['token'] ?? '');
  $dbHost = trim((string)($config['db_host'] ?? ''));
  $dbPort = $config['db_port'] ?? null;

  if (strlen($token) < 32) {
    throw new RuntimeException('Setup token must contain at least 32 bytes.');
  }
  if ($dbHost === '' || strlen($dbHost) > 255 || preg_match('/[\x00-\x20;=]/', $dbHost)) {
    throw new RuntimeException('Configured database host is invalid.');
  }
  if ($dbPort !== null && $dbPort !== '') {
    $dbPort = filter_var($dbPort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($dbPort === false) {
      throw new RuntimeException('Configured database port is invalid.');
    }
  } else {
    $dbPort = null;
  }

  return [
    'path' => $realPath,
    'token' => $token,
    'db_host' => $dbHost,
    'db_port' => $dbPort,
  ];
}

function install_is_authorized(array $setupConfig): bool {
  $authorizedAt = (int)($_SESSION['install_authorized_at'] ?? 0);
  $fingerprint = (string)($_SESSION['install_secret_fingerprint'] ?? '');
  if ($authorizedAt <= 0 || (time() - $authorizedAt) > 900 || $fingerprint === '') {
    return false;
  }
  return hash_equals(hash('sha256', (string)$setupConfig['token']), $fingerprint);
}

function install_csrf_valid(): bool {
  $expected = (string)($_SESSION['install_csrf'] ?? '');
  $submitted = (string)($_POST['csrf'] ?? '');
  return $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);
}

function install_database_dsn(array $setupConfig, string $dbName): string {
  if (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/', $dbName)) {
    throw new InvalidArgumentException('Database name contains unsupported characters.');
  }

  $dsn = 'mysql:host=' . $setupConfig['db_host'];
  if ($setupConfig['db_port'] !== null) {
    $dsn .= ';port=' . (int)$setupConfig['db_port'];
  }
  return $dsn . ';dbname=' . $dbName . ';charset=utf8mb4';
}

function install_write_config_atomically(string $configPath, string $contents, string $privateDirectory): void {
  $tempPath = tempnam($privateDirectory, '.cool-rooms-config-');
  if ($tempPath === false) {
    throw new RuntimeException('Temporary configuration file could not be created.');
  }

  try {
    if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
      throw new RuntimeException('Configuration could not be written.');
    }
    @chmod($tempPath, 0600);
    if (is_file($configPath) || !@rename($tempPath, $configPath)) {
      throw new RuntimeException('Configuration could not be published.');
    }
  } finally {
    if (is_file($tempPath)) {
      @unlink($tempPath);
    }
  }
}

function install_recover_stale_marker(string $markerPath): bool {
  if (!is_file($markerPath)) {
    return true;
  }

  $markerHandle = @fopen($markerPath, 'c+');
  if ($markerHandle === false) {
    return false;
  }

  $stale = false;
  try {
    if (!flock($markerHandle, LOCK_EX | LOCK_NB)) {
      return false;
    }
    rewind($markerHandle);
    $state = trim((string)stream_get_contents($markerHandle));
    if (!str_starts_with($state, 'installing')) {
      return false;
    }
    $stale = true;
  } finally {
    flock($markerHandle, LOCK_UN);
    fclose($markerHandle);
  }
  return $stale && @unlink($markerPath);
}

try {
  $setupConfigUsesOverride = trim((string)(getenv('COOL_ROOMS_INSTALL_CONFIG') ?: '')) !== '';
  $setupConfigPath = install_setup_config_path();
  $expectedSetupFilename = basename($setupConfigPath);
  $installMarkerPath = $setupConfigPath . '.complete';

  if (is_file($configPath)) {
    $blockedMessage = 'Der Installer ist dauerhaft deaktiviert. Für eine bewusste Neuinstallation müssen Konfiguration, Sperrmarker und Datenbank kontrolliert zurückgesetzt werden.';
  } elseif (is_file($installMarkerPath) && !install_recover_stale_marker($installMarkerPath)) {
    $blockedMessage = 'Der Installer ist dauerhaft deaktiviert oder eine Installation wird gerade ausgeführt.';
  } else {
    $setupConfig = install_load_setup_config($setupConfigPath);
  }
} catch (Throwable $e) {
  install_log_error($e);
  if ($setupConfigPath !== '' && !is_file($setupConfigPath)) {
    $setupConfigMissing = true;
    $blockedMessage = 'Die externe Installationsdatei wurde noch nicht gefunden.';
  } else {
    $blockedMessage = 'Die Installation ist serverseitig nicht freigeschaltet. Prüfe die externe Installationsdatei gemäß README.';
  }
}

if ($blockedMessage !== '') {
  http_response_code(403);
  unset($_SESSION['install_authorized_at'], $_SESSION['install_secret_fingerprint']);
}

function detect_base_url(): string {
  $script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? '');
  // /cool-rooms/install.php -> dirname => /cool-rooms
  $dir = rtrim(dirname($script), '/');
  if ($dir === '/' || $dir === '.') return '';
  return $dir;
}

function install_table_columns(PDO $pdo, string $table): array {
  $stmt = $pdo->prepare("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
  ");
  $stmt->execute([$table]);
  return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function install_assert_compatible_table(PDO $pdo, string $table, array $requiredColumns): void {
  $columns = install_table_columns($pdo, $table);
  if (!$columns) {
    return;
  }

  $missing = array_values(array_diff($requiredColumns, $columns));
  if ($missing) {
    throw new RuntimeException(
      'Die ausgewählte Datenbank enthält bereits eine nicht kompatible Tabelle „' . $table . '“ ' .
      '(fehlende COOL-Rooms-Spalten: ' . implode(', ', $missing) . '). ' .
      'Bitte eine leere, ausschließlich für COOL Rooms vorgesehene Datenbank verwenden oder die Tabellen des falschen Installationsversuchs entfernen.'
    );
  }
}

function install_assert_compatible_database(PDO $pdo): void {
  install_assert_compatible_table($pdo, 'users', ['role', 'password_hash', 'class_name', 'is_admin']);
  install_assert_compatible_table($pdo, 'rooms', ['name', 'capacity', 'is_teacher_only', 'is_active']);
  install_assert_compatible_table($pdo, 'app_settings', ['k', 'v']);
}

if ($blockedMessage === '' && $setupConfig !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
  if (!install_csrf_valid()) {
    http_response_code(400);
    $err = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
  } else {
    $submittedToken = (string)($_POST['setup_token'] ?? '');
    if ($submittedToken === '' || !hash_equals((string)$setupConfig['token'], $submittedToken)) {
      http_response_code(403);
      $err = 'Installation nicht freigeschaltet oder Setup-Kennwort ungültig.';
    } else {
      session_regenerate_id(true);
      $_SESSION['install_authorized_at'] = time();
      $_SESSION['install_secret_fingerprint'] = hash('sha256', (string)$setupConfig['token']);
      $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
      header('Location: install.php');
      exit;
    }
  }
}

$installAuthorized = $setupConfig !== null && install_is_authorized($setupConfig);

if ($blockedMessage === '' && $setupConfig !== null && $installAuthorized && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
  $db_host = (string)$setupConfig['db_host'];
  $db_name = trim($_POST['db_name'] ?? '');
  $db_user = trim($_POST['db_user'] ?? '');
  $db_pass = (string)($_POST['db_pass'] ?? '');
  $app_title = trim($_POST['app_title'] ?? 'COOL Rooms');
  $app_subtitle = trim($_POST['app_subtitle'] ?? 'Buche deinen Raum');

  $admin_username = trim($_POST['admin_username'] ?? '');
  $admin_pass  = (string)($_POST['admin_pass'] ?? '');
  $admin_fn    = trim($_POST['admin_first_name'] ?? 'Admin');
  $admin_ln    = trim($_POST['admin_last_name'] ?? 'User');

  if (!install_csrf_valid()) {
    http_response_code(400);
    $err = 'Die Anfrage ist abgelaufen. Bitte lade die Seite neu.';
  } elseif ($db_name === '' || $db_user === '' || $admin_username === '' || $admin_pass === '') {
    $err = "Bitte alle Pflichtfelder ausfüllen.";
  } elseif (!preg_match('/\A[A-Za-z0-9_$-]{1,64}\z/', $db_name)) {
    $err = 'Der Datenbankname darf nur Buchstaben, Ziffern, Unterstrich, Bindestrich und Dollarzeichen enthalten.';
  } else {
    if (mb_strlen($admin_pass, '8bit') < 8) {
      $err = "Admin Passwort muss mindestens 8 Zeichen haben.";
    } else {
      $markerHandle = @fopen($installMarkerPath, 'x');
      if ($markerHandle === false) {
        http_response_code(409);
        $err = 'Die Installation ist bereits abgeschlossen oder wird gerade ausgeführt.';
      } else {
        $markerCreated = true;
        $configPublished = false;
        $pdo = null;
      try {
      if (!flock($markerHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Installation lock could not be acquired.');
      }
      @chmod($installMarkerPath, 0600);
      fwrite($markerHandle, "installing\n");
      fflush($markerHandle);

      if (is_file($configPath)) {
        throw new RuntimeException('Installation was completed concurrently.');
      }
      $lockedSetupConfig = install_load_setup_config($setupConfigPath);
      if (!hash_equals((string)$setupConfig['token'], (string)$lockedSetupConfig['token']) || !install_is_authorized($lockedSetupConfig)) {
        throw new RuntimeException('Setup authorization changed before installation.');
      }

      $dsn = install_database_dsn($lockedSetupConfig, $db_name);
      $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);

      // Do not alter tables that belong to another application or installation attempt.
      install_assert_compatible_database($pdo);

      // Core tables
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          role ENUM('admin','teacher','student') NOT NULL,
          first_name VARCHAR(80) NOT NULL,
          last_name VARCHAR(80) NOT NULL,
          username VARCHAR(190) NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          class_name VARCHAR(80) NULL,
          is_admin TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          must_change_password TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS rooms (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(120) NOT NULL UNIQUE,
          capacity INT NULL,
          group_type VARCHAR(20) NULL,
          group_name VARCHAR(80) NULL,
          group_color VARCHAR(7) NULL,
          is_teacher_only TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_group_type_settings (
          group_type VARCHAR(20) NOT NULL PRIMARY KEY,
          sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_group_settings (
          id INT AUTO_INCREMENT PRIMARY KEY,
          group_type VARCHAR(20) NOT NULL,
          group_name VARCHAR(80) NOT NULL,
          group_color VARCHAR(7) NULL,
          sort_order INT NOT NULL DEFAULT 0,
          UNIQUE KEY uq_room_group (group_type, group_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");
      $pdo->exec("
        INSERT IGNORE INTO room_group_type_settings (group_type, sort_order)
        VALUES ('floor', 1), ('location', 2), ('function', 3)
      ");

      $pdo->exec("
  CREATE TABLE IF NOT EXISTS room_assignments (
    student_id INT PRIMARY KEY,
    room_id INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ra_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ra_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS teacher_locations (
    teacher_id INT PRIMARY KEY,
    room_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tl_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tl_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Ensure teacher lounge exists (teacher-only room)
$pdo->exec("INSERT IGNORE INTO rooms (name, capacity, is_teacher_only, is_active) VALUES ('Lehrerzimmer', NULL, 1, 1)");

$pdo->exec("
        CREATE TABLE IF NOT EXISTS teacher_responsibility (
          teacher_id INT PRIMARY KEY,
          class_name VARCHAR(80) NOT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT fk_tr_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS class_settings (
          class_name VARCHAR(80) PRIMARY KEY,
          home_room_id INT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT fk_cs_room FOREIGN KEY (home_room_id) REFERENCES rooms(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      // Settings for kiosk etc.
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
          k VARCHAR(64) PRIMARY KEY,
          v TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $installFingerprint = hash('sha256', 'cool-rooms-installer-v1:' . (string)$lockedSetupConfig['token']);
      $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k = ? LIMIT 1");
      $stmt->execute(['installation_fingerprint']);
      $storedInstallFingerprint = $stmt->fetchColumn();
      $resumeCommittedInstall = is_string($storedInstallFingerprint) && $storedInstallFingerprint !== '';
      if ($resumeCommittedInstall && !hash_equals($installFingerprint, $storedInstallFingerprint)) {
        throw new RuntimeException('Database belongs to another installation attempt.');
      }

      if (!$resumeCommittedInstall && (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
        throw new RuntimeException('Database already contains users and cannot be initialized safely.');
      }


      // Class-specific kiosk codes
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS kiosk_class_codes (
          id INT AUTO_INCREMENT PRIMARY KEY,
          class_name VARCHAR(50) NOT NULL,
          valid_date DATE NOT NULL,
          code_hash VARCHAR(255) NOT NULL,
          code_encrypted TEXT NULL,
          updated_by INT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_class_date (class_name, valid_date),
          INDEX (valid_date),
          CONSTRAINT fk_kcc_teacher FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_attempts (
          bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
          scope VARCHAR(24) NOT NULL,
          failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          locked_until DATETIME NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_auth_attempts_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      // Event log for Live-View
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          type VARCHAR(80) NOT NULL,
          payload TEXT NULL,

          -- client / kiosk audit fields (nullable; do not broadcast via SSE payload)
          client_ip VARCHAR(45) NULL,
          client_user_agent VARCHAR(255) NULL,
          client_os VARCHAR(40) NULL,
          client_device VARCHAR(20) NULL,
          client_id VARCHAR(64) NULL,
          via_kiosk TINYINT(1) NOT NULL DEFAULT 0,
          kiosk_scope_type VARCHAR(10) NULL,
          kiosk_scope_class VARCHAR(50) NULL,

          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (created_at),
          INDEX (type),
          INDEX (client_id),
          INDEX (via_kiosk)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      if (!$resumeCommittedInstall) {
        // Keep all data rows transactional after the schema has been prepared.
        $pdo->beginTransaction();

        // Default settings
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $defaults = [
          'kiosk_enabled' => '0',
          'kiosk_day_code' => '',
          'kiosk_day_code_hash' => '',
          'kiosk_day_code_encrypted' => '',
          'kiosk_valid_from' => $today,
          'kiosk_valid_to' => $today,
          'kiosk_expires' => $today,
          'auth_max_attempts' => '5',
          'auth_lock_minutes' => '15',
          'brand_title' => $app_title !== '' ? $app_title : 'COOL Rooms',
          'brand_subtitle' => $app_subtitle,
          'brand_logo' => '/assets/cool-rooms-logo.svg',
          'installation_fingerprint' => $installFingerprint,
        ];
        $stmt = $pdo->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
        foreach ($defaults as $k => $v) $stmt->execute([$k,$v]);

        // Admin user
        $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role, first_name, last_name, username, password_hash, is_admin) VALUES ('admin',?,?,?,?,1)");
        $stmt->execute([$admin_fn, $admin_ln, $admin_username, $hash]);

        // Some default rooms (optional)
        $pdo->exec("INSERT IGNORE INTO rooms (name,is_active) VALUES
          ('Bibliothek',1),('Lernatelier',1),('PC-Raum',1),('Gruppenraum 1',1),('Gruppenraum 2',1)
        ");

        // Commit first. A retry can recognize this exact installation and only publish config.php.
        $pdo->commit();
      }

      // Write config.php
      $config = [
        'db_host' => $db_host,
        'db_name' => $db_name,
        'db_user' => $db_user,
        'db_pass' => $db_pass,
        'credential_key' => base64_encode(random_bytes(32)),
        'app_name' => $app_title !== '' ? $app_title : 'COOL Rooms',
        'app_title' => $app_title !== '' ? $app_title : 'COOL Rooms',
        'app_subtitle' => $app_subtitle,
        'base_url' => detect_base_url(),
      ];
      $php = "<?php\nreturn " . var_export($config, true) . ";\n";
      install_write_config_atomically($configPath, $php, dirname((string)$lockedSetupConfig['path']));
      $configPublished = true;

      rewind($markerHandle);
      ftruncate($markerHandle, 0);
      fwrite($markerHandle, 'completed ' . date(DATE_ATOM) . "\n");
      fflush($markerHandle);

      if (!@unlink((string)$lockedSetupConfig['path'])) {
        error_log('[COOL Rooms installer] Installation completed, but the consumed setup file could not be removed.');
      }

      unset($_SESSION['install_authorized_at'], $_SESSION['install_secret_fingerprint']);

      $ok = true;
    } catch (Throwable $e) {
      if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      if ($configPublished && is_file($configPath)) {
        @unlink($configPath);
      }
      if (is_resource($markerHandle)) {
        fclose($markerHandle);
        $markerHandle = null;
      }
      if ($markerCreated && is_file($installMarkerPath)) {
        @unlink($installMarkerPath);
      }
      $reference = install_log_error($e);
      $err = 'Installation fehlgeschlagen. Technische Details wurden im Serverprotokoll unter Referenz ' . $reference . ' gespeichert.';
    } finally {
      if (is_resource($markerHandle)) {
        flock($markerHandle, LOCK_UN);
        fclose($markerHandle);
      }
    }
    }
    }
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installer · COOL Rooms</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
    .install-guide{margin-top:18px;padding-top:4px}
    .install-guide h2{margin:8px 0 4px}
    .install-guide h3{margin:22px 0 6px}
    .install-guide pre{overflow:auto;padding:14px;border-radius:12px;background:#111827;color:#f8fafc;line-height:1.5}
    .install-guide ol{padding-left:22px}
    .install-guide li{margin:8px 0}
    .install-token{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace}
    .install-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
    .install-actions button{width:auto}
    .install-note{padding:12px;border-left:4px solid var(--theme-warning);background:color-mix(in srgb,var(--theme-warning) 9%,transparent);border-radius:8px}
  </style>
</head>
<body>
<div class="wrap">
  <h1>COOL Rooms · Installation</h1>
  <div class="card">
    <?php if ($err): ?><div class="flash error"><?= h($err) ?></div><?php endif; ?>

    <?php if ($setupConfigMissing): ?>
      <div class="flash error">
        <p><strong>Installationsdatei fehlt.</strong></p>
        <?php if ($setupConfigUsesOverride): ?>
          <p>Die über <span class="kbd">COOL_ROOMS_INSTALL_CONFIG</span> eingestellte Datei wurde nicht gefunden. Prüfe den dort hinterlegten Pfad und die Dateiberechtigungen.</p>
          <p>Erwarteter Dateiname: <span class="kbd"><?= h($expectedSetupFilename) ?></span></p>
        <?php else: ?>
          <p>Lege eine Kopie von <span class="kbd">install-secret.sample.php</span> direkt oberhalb des Dokumentenverzeichnisses ab.</p>
          <p>Verwende dafür exakt diesen Dateinamen:</p>
          <p><span class="kbd"><?= h($expectedSetupFilename) ?></span></p>
          <p>Liegt die Anwendung beispielsweise in <span class="kbd">/users/cool-rooms/www</span>, gehört die Datei nach <span class="kbd">/users/cool-rooms</span>. Eine Umgebungsvariable ist für diesen Standardweg nicht erforderlich.</p>
        <?php endif; ?>
      </div>
      <section class="install-guide" aria-labelledby="install-guide-title">
        <h2 id="install-guide-title">Installationsdatei vorbereiten</h2>
        <p>Die Datei gibt den Datenbankserver fest vor und schützt den Installer mit einem einmaligen Setup-Kennwort. Sie wird nach einer erfolgreichen Installation automatisch gelöscht.</p>

        <h3>1. Sicheres Setup-Kennwort erzeugen</h3>
        <p>Der Code muss mindestens 32 zufällige Bytes enthalten. Die folgende Schaltfläche erzeugt 32 kryptografisch sichere Zufallsbytes als 64-stelligen Hexadezimalcode direkt in diesem Browser. Dabei wird nichts an einen anderen Server übertragen.</p>
        <label for="generated-setup-token">Erzeugter Setup-Code</label>
        <input id="generated-setup-token" class="install-token" type="text" readonly placeholder="Zuerst „Sicheren Code erzeugen“ wählen">
        <div class="install-actions">
          <button type="button" class="small" id="generate-setup-token">Sicheren Code erzeugen</button>
          <button type="button" class="small secondary" id="copy-setup-token" disabled>Code kopieren</button>
        </div>
        <p id="setup-token-status" class="small" aria-live="polite"></p>
        <p class="small">Alternativ auf einem Rechner mit PHP ausführen:</p>
        <pre><code>php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"</code></pre>

        <h3>2. Datei anlegen</h3>
        <ol>
          <li>Eine Kopie von <span class="kbd">install-secret.sample.php</span> erstellen.</li>
          <?php if ($setupConfigUsesOverride): ?>
            <li>Die Kopie mit dem oben angezeigten Dateinamen an dem in <span class="kbd">COOL_ROOMS_INSTALL_CONFIG</span> festgelegten Ort ablegen.</li>
          <?php else: ?>
            <li>Die Kopie mit dem oben angezeigten Dateinamen direkt oberhalb des Webverzeichnisses ablegen.</li>
          <?php endif; ?>
          <li>Den erzeugten Setup-Code, den Datenbankserver und gegebenenfalls den Datenbankport eintragen.</li>
        </ol>
        <p>Der Dateiinhalt sieht so aus:</p>
        <pre><code>&lt;?php
declare(strict_types=1);

return [
  'token' =&gt; '<span id="setup-token-example">HIER_DEN_64-STELLIGEN_SETUP-CODE_EINTRAGEN</span>',
  'db_host' =&gt; 'localhost',
  'db_port' =&gt; null,
];</code></pre>

        <h3>3. Werte richtig eintragen</h3>
        <p><strong>token:</strong> Den oben erzeugten 64-stelligen Code vollständig zwischen den einfachen Anführungszeichen einsetzen.</p>
        <p><strong>db_host:</strong> Den Datenbankserver des Hostinganbieters einsetzen. Häufig lautet er <span class="kbd">localhost</span>; möglich sind aber auch Angaben wie <span class="kbd">mysql.example-host.at</span>. Hier gehört keine Webadresse mit <span class="kbd">https://</span> hinein.</p>
        <p><strong>db_port:</strong> Normalerweise bleibt der Wert <span class="kbd">null</span>. Nur wenn der Hostinganbieter ausdrücklich einen abweichenden MySQL-/MariaDB-Port nennt, wird beispielsweise <span class="kbd">3307</span> eingetragen.</p>

        <p class="install-note"><strong>Keine externen Secret-Generatoren verwenden:</strong> Ein fremder Dienst könnte den erzeugten Code kennen oder protokollieren. Die lokale Browser-Schaltfläche oder PHPs <span class="kbd">random_bytes()</span> sind dafür sicherer.</p>
        <p class="small">Technische Hintergründe: <a href="https://www.php.net/manual/en/function.random-bytes.php" target="_blank" rel="noopener noreferrer">PHP-Dokumentation zu random_bytes()</a> · <a href="https://www.w3.org/TR/webcrypto/#Crypto-method-getRandomValues" target="_blank" rel="noopener noreferrer">W3C Web Cryptography API</a></p>

        <h3>4. Installation fortsetzen</h3>
        <p>Die fertige Datei hochladen und diese Seite neu laden. Danach erscheint die Eingabe für das Setup-Kennwort.</p>
      </section>
    <?php elseif ($blockedMessage !== ''): ?>
      <div class="flash error">
        <p><strong>Installation gesperrt.</strong></p>
        <p><?= h($blockedMessage) ?></p>
      </div>
    <?php elseif ($ok): ?>
      <div class="flash success">
        <p><strong>Installation erfolgreich.</strong></p>
        <p>Das Setup-Kennwort wurde verbraucht und der Installer dauerhaft gesperrt.</p>
        <p>Du kannst <span class="kbd">install.php</span> zusätzlich vom produktiven Webspace entfernen.</p>
        <p><a href="index.php">Zum Login</a> · <a href="kiosk.php">Zum Kiosk</a></p>
      </div>
    <?php elseif (!$installAuthorized): ?>
      <h3>Installation freischalten</h3>
      <p>Gib das Setup-Kennwort aus der externen Installationsdatei ein.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['install_csrf']) ?>">
        <input type="hidden" name="action" value="unlock">
        <div>
          <label>Setup-Kennwort</label>
          <input name="setup_token" type="password" required autocomplete="off">
        </div>
        <p style="margin-top:12px;"><button type="submit">Installation freischalten</button></p>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['install_csrf']) ?>">
        <input type="hidden" name="action" value="install">
        <h3>Datenbank</h3>
        <div class="row">
          <div><label>DB Host</label><input value="<?= h((string)$setupConfig['db_host']) ?><?= $setupConfig['db_port'] !== null ? ':' . (int)$setupConfig['db_port'] : '' ?>" readonly></div>
          <div><label>DB Name *</label><input name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>"></div>
        </div>
        <div class="row">
          <div><label>DB User *</label><input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>"></div>
          <div><label>DB Passwort</label><input name="db_pass" type="password"></div>
        </div>

        <h3>App</h3>
        <div class="row">
          <div><label>Titel</label><input name="app_title" value="<?= h($_POST['app_title'] ?? 'COOL Rooms') ?>"></div>
          <div><label>Untertitel</label><input name="app_subtitle" value="<?= h($_POST['app_subtitle'] ?? 'Buche deinen Raum') ?>"></div>
        </div>

        <h3>Erster Admin</h3>
        <div class="row">
          <div><label>Vorname</label><input name="admin_first_name" value="<?= h($_POST['admin_first_name'] ?? 'Admin') ?>"></div>
          <div><label>Nachname</label><input name="admin_last_name" value="<?= h($_POST['admin_last_name'] ?? 'User') ?>"></div>
        </div>
        <div class="row">
          <div><label>Admin Username *</label><input name="admin_username" value="<?= h($_POST['admin_username'] ?? '') ?>" required autocomplete="username"></div>
          <div><label>Admin Passwort * (mind. 8 Zeichen)</label><input name="admin_pass" type="password"></div>
        </div>

        <p style="margin-top:12px;"><button type="submit">Installieren</button></p>

        <p class="small">
          Hinweis: <strong>base_url</strong> wird automatisch erkannt (z. B. <span class="kbd">/cool-rooms</span>).
          Falls nötig, kannst du später in <span class="kbd">config.php</span> manuell anpassen.
        </p>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php if ($setupConfigMissing): ?>
<script>
(() => {
  const output = document.getElementById('generated-setup-token');
  const example = document.getElementById('setup-token-example');
  const generateButton = document.getElementById('generate-setup-token');
  const copyButton = document.getElementById('copy-setup-token');
  const status = document.getElementById('setup-token-status');

  if (!output || !example || !generateButton || !copyButton || !status) return;

  generateButton.addEventListener('click', () => {
    if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
      status.textContent = 'Dieser Browser unterstützt die sichere Erzeugung nicht. Bitte verwende den angezeigten PHP-Befehl.';
      return;
    }
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    const token = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    output.value = token;
    example.textContent = token;
    copyButton.disabled = false;
    status.textContent = 'Der Code wurde ausschließlich in diesem Browser erzeugt.';
  });

  copyButton.addEventListener('click', async () => {
    if (!output.value) return;
    try {
      await navigator.clipboard.writeText(output.value);
      status.textContent = 'Der Setup-Code wurde kopiert.';
    } catch (error) {
      output.focus();
      output.select();
      status.textContent = 'Der Code wurde markiert. Bitte kopiere ihn manuell.';
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
