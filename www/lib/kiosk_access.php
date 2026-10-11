<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/secrets.php';

function kiosk_setting_get(PDO $pdo, string $key, string $default = ''): string {
  $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
  $stmt->execute([$key]);
  $value = $stmt->fetchColumn();
  return $value === false ? $default : (string)$value;
}

function kiosk_setting_set(PDO $pdo, string $key, string $value): void {
  $stmt = $pdo->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $stmt->execute([$key, $value]);
}

function kiosk_valid_date(string $value, string $default): string {
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  return ($date && $date->format('Y-m-d') === $value) ? $value : $default;
}

function kiosk_ensure_secure_storage(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;

  $legacySchoolCode = kiosk_setting_get($pdo, 'kiosk_day_code', '');
  if ($legacySchoolCode !== '') {
    if (kiosk_setting_get($pdo, 'kiosk_day_code_hash', '') === '') {
      kiosk_setting_set($pdo, 'kiosk_day_code_hash', password_hash($legacySchoolCode, PASSWORD_DEFAULT));
    }
    if (kiosk_setting_get($pdo, 'kiosk_day_code_encrypted', '') === '') {
      kiosk_setting_set($pdo, 'kiosk_day_code_encrypted', secret_encrypt($legacySchoolCode));
    }
    kiosk_setting_set($pdo, 'kiosk_day_code', '');
  }

  $table = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kiosk_class_codes' LIMIT 1")->fetchColumn();
  if (!$table) {
    // Older installations may not have the optional class-kiosk table yet.
    $ready = true;
    return;
  }

  $stmt = $pdo->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kiosk_class_codes' AND COLUMN_NAME=?
    LIMIT 1
  ");
  $stmt->execute(['code_encrypted']);
  if (!$stmt->fetchColumn()) {
    $pdo->exec("ALTER TABLE kiosk_class_codes ADD COLUMN code_encrypted TEXT NULL AFTER code_hash");
  }

  $column = $pdo->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='kiosk_class_codes' AND COLUMN_NAME=?
    LIMIT 1
  ");
  $column->execute(['code_plain']);
  if ($column->fetchColumn()) {
    $rows = $pdo->query("SELECT id, code_plain FROM kiosk_class_codes WHERE code_plain IS NOT NULL AND code_plain<>''")->fetchAll();
    $update = $pdo->prepare("UPDATE kiosk_class_codes SET code_encrypted=? WHERE id=?");
    foreach ($rows as $row) {
      $update->execute([secret_encrypt((string)$row['code_plain']), (int)$row['id']]);
    }

    // Remove the legacy column after all values have been migrated.
    $pdo->exec("ALTER TABLE kiosk_class_codes DROP COLUMN code_plain");
  }

  $ready = true;
}

function kiosk_school_code(PDO $pdo): string {
  kiosk_ensure_secure_storage($pdo);
  return secret_decrypt(kiosk_setting_get($pdo, 'kiosk_day_code_encrypted', '')) ?? '';
}

function kiosk_set_school_code(PDO $pdo, string $code): void {
  kiosk_ensure_secure_storage($pdo);
  kiosk_setting_set($pdo, 'kiosk_day_code_hash', $code === '' ? '' : password_hash($code, PASSWORD_DEFAULT));
  kiosk_setting_set($pdo, 'kiosk_day_code_encrypted', $code === '' ? '' : secret_encrypt($code));
  kiosk_setting_set($pdo, 'kiosk_day_code', '');
}

function kiosk_verify_school_code(PDO $pdo, string $code): bool {
  kiosk_ensure_secure_storage($pdo);
  $hash = kiosk_setting_get($pdo, 'kiosk_day_code_hash', '');
  return $code !== '' && $hash !== '' && password_verify($code, $hash);
}

function kiosk_class_code(PDO $pdo, string $class, string $date): string {
  kiosk_ensure_secure_storage($pdo);
  $stmt = $pdo->prepare("SELECT code_encrypted FROM kiosk_class_codes WHERE class_name=? AND valid_date=? LIMIT 1");
  $stmt->execute([$class, $date]);
  return secret_decrypt((string)($stmt->fetchColumn() ?: '')) ?? '';
}

function kiosk_access_state(PDO $pdo, ?string $today = null): array {
  $today = $today ?: (new DateTimeImmutable('now'))->format('Y-m-d');
  $expires = kiosk_setting_get($pdo, 'kiosk_expires', $today);
  $from = kiosk_valid_date(kiosk_setting_get($pdo, 'kiosk_valid_from', $expires ?: $today), $today);
  $to = kiosk_valid_date(kiosk_setting_get($pdo, 'kiosk_valid_to', $expires ?: $today), $from);
  return [
    'today' => $today,
    'enabled' => kiosk_setting_get($pdo, 'kiosk_enabled', '0') === '1',
    'valid_from' => $from,
    'valid_to' => $to,
    'valid_today' => kiosk_setting_get($pdo, 'kiosk_enabled', '0') === '1' && $today >= $from && $today <= $to,
  ];
}

function kiosk_grant_fingerprint(PDO $pdo, string $scopeType, string $scopeClass, string $today): ?string {
  kiosk_ensure_secure_storage($pdo);
  $state = kiosk_access_state($pdo, $today);
  if (!$state['valid_today']) return null;

  if ($scopeType === 'class') {
    $stmt = $pdo->prepare("SELECT code_hash FROM kiosk_class_codes WHERE class_name=? AND valid_date=? LIMIT 1");
    $stmt->execute([$scopeClass, $today]);
    $codeHash = (string)($stmt->fetchColumn() ?: '');
    if ($scopeClass === '' || $codeHash === '') return null;
  } else {
    $scopeType = 'all';
    $scopeClass = '';
    $codeHash = kiosk_setting_get($pdo, 'kiosk_day_code_hash', '');
    if ($codeHash === '') return null;
  }

  return hash_hmac('sha256', implode("\0", [
    $scopeType,
    $scopeClass,
    $today,
    (string)$state['valid_from'],
    (string)$state['valid_to'],
    $codeHash,
  ]), app_secret_key());
}

function kiosk_mark_verified(PDO $pdo, string $scopeType, string $scopeClass, string $today): bool {
  $fingerprint = kiosk_grant_fingerprint($pdo, $scopeType, $scopeClass, $today);
  if ($fingerprint === null) return false;
  $_SESSION['kiosk_verified_until'] = $today;
  $_SESSION['kiosk_scope_type'] = $scopeType;
  if ($scopeType === 'class') {
    $_SESSION['kiosk_scope_class'] = $scopeClass;
  } else {
    unset($_SESSION['kiosk_scope_class']);
  }
  $_SESSION['kiosk_grant_fingerprint'] = $fingerprint;
  return true;
}

function kiosk_session_grant_valid(PDO $pdo, ?string $today = null): bool {
  $today = $today ?: (new DateTimeImmutable('now'))->format('Y-m-d');
  if ((string)($_SESSION['kiosk_verified_until'] ?? '') !== $today) return false;
  $scopeType = (string)($_SESSION['kiosk_scope_type'] ?? 'all');
  $scopeClass = (string)($_SESSION['kiosk_scope_class'] ?? '');
  $expected = kiosk_grant_fingerprint($pdo, $scopeType, $scopeClass, $today);
  $actual = (string)($_SESSION['kiosk_grant_fingerprint'] ?? '');
  return $expected !== null && $actual !== '' && hash_equals($expected, $actual);
}

function kiosk_clear_session_identity(): void {
  unset(
    $_SESSION['uid'],
    $_SESSION['last_activity'],
    $_SESSION['via_kiosk'],
    $_SESSION['kiosk_verified_until'],
    $_SESSION['kiosk_scope_type'],
    $_SESSION['kiosk_scope_class'],
    $_SESSION['kiosk_grant_fingerprint']
  );
}
