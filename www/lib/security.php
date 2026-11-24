<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/secrets.php';

function security_setting_int(PDO $pdo, string $key, int $default, int $min, int $max): int {
  $stmt = $pdo->prepare('SELECT v FROM app_settings WHERE k=? LIMIT 1');
  $stmt->execute([$key]);
  $value = $stmt->fetchColumn();
  return max($min, min($max, $value === false ? $default : (int)$value));
}

function security_login_limits(PDO $pdo): array {
  return [
    'attempts' => security_setting_int($pdo, 'auth_max_attempts', 5, 3, 20),
    'lock_minutes' => security_setting_int($pdo, 'auth_lock_minutes', 15, 1, 30),
    'login_per_minute' => security_setting_int($pdo, 'auth_login_per_minute', 600, 100, 5000),
    'kiosk_per_minute' => security_setting_int($pdo, 'auth_kiosk_per_minute', 120, 30, 1000),
  ];
}

function security_client_ip(): string {
  $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
  // Do not trust client-supplied forwarding headers.
  return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function security_ensure_throttle_schema(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;
  $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
    bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
    scope VARCHAR(24) NOT NULL,
    failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auth_attempts_updated (updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Upgrade exactly once; preserve account limits and all new scopes.
  $pdo->exec("INSERT IGNORE INTO app_settings(k,v) VALUES('auth_scope_version','1')");
  $pdo->beginTransaction();
  try {
    $version = $pdo->query("SELECT v FROM app_settings WHERE k='auth_scope_version' FOR UPDATE")->fetchColumn();
    if ($version !== '2') {
      $pdo->exec("DELETE FROM auth_attempts WHERE scope IN ('login_source','kiosk_source','kiosk_global')");
      $pdo->exec("UPDATE app_settings SET v='2' WHERE k='auth_scope_version'");
    }
    $pdo->commit();
    $ready = true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function security_throttle_bucket(string $scope, string $identifier = '', bool $includeIp = true): string {
  $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($identifier), 'UTF-8') : strtolower(trim($identifier));
  return hash('sha256', $scope . "\0" . $normalized . "\0" . ($includeIp ? security_client_ip() : ''));
}

/** Signed session cookie; survives logout, but never grants kiosk access. */
function security_browser_id(): string {
  $path = rtrim((string)(app_config()['base_url'] ?? ''), '/') . '/';
  $name = 'cr_auth_browser_' . substr(hash('sha256', $path), 0, 8);
  $cookie = (string)($_COOKIE[$name] ?? '');
  if (preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $cookie, $matches)
    && hash_equals(hash_hmac('sha256', 'auth-browser:' . $matches[1], app_secret_key()), $matches[2])) {
    $_SESSION['auth_browser_id'] = $matches[1];
    return $matches[1];
  }
  $id = (string)($_SESSION['auth_browser_id'] ?? '');
  if (!preg_match('/^[a-f0-9]{64}$/D', $id)) $id = bin2hex(random_bytes(32));
  $_SESSION['auth_browser_id'] = $id;
  $value = $id . '.' . hash_hmac('sha256', 'auth-browser:' . $id, app_secret_key());
  if (!headers_sent()) {
    setcookie($name, $value, [
      'expires' => 0, 'path' => $path,
      'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'httponly' => true, 'samesite' => 'Lax',
    ]);
  }
  $_COOKIE[$name] = $value;
  return $id;
}

function security_throttle_status(PDO $pdo, string $scope, string $identifier = '', bool $includeIp = true): array {
  security_ensure_throttle_schema($pdo);
  $stmt = $pdo->prepare('SELECT locked_until > UTC_TIMESTAMP() AS is_locked,
    GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until)) AS retry_after
    FROM auth_attempts WHERE bucket_hash=?');
  $stmt->execute([security_throttle_bucket($scope, $identifier, $includeIp)]);
  $row = $stmt->fetch();
  return ['locked' => $row && (int)$row['is_locked'] === 1, 'retry_after' => $row ? (int)$row['retry_after'] : 0];
}

/** Reserve before credential verification, including concurrent first requests. */
function security_take_attempt(PDO $pdo, string $scope, string $identifier, bool $includeIp, int $limit, int $seconds, bool $burst = false): array {
  security_ensure_throttle_schema($pdo);
  $bucket = security_throttle_bucket($scope, $identifier, $includeIp);
  $pdo->beginTransaction();
  try {
    // No-op upsert takes an exclusive lock even for a newly created bucket.
    $stmt = $pdo->prepare('INSERT INTO auth_attempts(bucket_hash,scope) VALUES (?,?)
      ON DUPLICATE KEY UPDATE bucket_hash=VALUES(bucket_hash)');
    $stmt->execute([$bucket, $scope]);
    $stmt = $pdo->prepare('SELECT failures, locked_until,
      locked_until > UTC_TIMESTAMP() AS is_future,
      GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until)) AS retry_after
      FROM auth_attempts WHERE bucket_hash=? FOR UPDATE');
    $stmt->execute([$bucket]);
    $row = $stmt->fetch();
    $future = (int)$row['is_future'] === 1;
    if ($future && (!$burst || (int)$row['failures'] >= $limit)) {
      $pdo->commit();
      return ['locked' => true, 'retry_after' => max(1, (int)$row['retry_after'])];
    }
    // Expired locks/windows restart with a full allowance.
    $count = ($row['locked_until'] !== null && !$future) ? 0 : (int)$row['failures'];
    $count++;
    $until = $future ? $row['locked_until'] : null;
    if (($burst && !$future) || (!$burst && $count >= $limit)) {
      $until = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }
    $stmt = $pdo->prepare('UPDATE auth_attempts SET failures=?, locked_until=? WHERE bucket_hash=?');
    $stmt->execute([$count, $until, $bucket]);
    $pdo->commit();
    // The last permitted attempt may be correct and must still be checked.
    return ['locked' => false, 'retry_after' => 0];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function security_throttle_clear(PDO $pdo, string $scope, string $identifier = '', bool $includeIp = true): void {
  security_ensure_throttle_schema($pdo);
  $stmt = $pdo->prepare('DELETE FROM auth_attempts WHERE bucket_hash=?');
  $stmt->execute([security_throttle_bucket($scope, $identifier, $includeIp)]);
}

function security_login_begin(PDO $pdo, string $username): array {
  $limits = security_login_limits($pdo);
  $source = security_take_attempt($pdo, 'login_burst', '', true, $limits['login_per_minute'], 60, true);
  if ($source['locked']) return $source + ['kind' => 'ip'];
  return security_take_attempt($pdo, 'login_account', $username, false, $limits['attempts'], $limits['lock_minutes'] * 60) + ['kind' => 'account'];
}

function security_login_status(PDO $pdo, string $username): array {
  return security_throttle_status($pdo, 'login_account', $username, false) + ['kind' => 'account'];
}

function security_login_failure(PDO $pdo, string $username): array {
  return security_login_status($pdo, $username);
}

function security_login_success(PDO $pdo, string $username): void {
  security_throttle_clear($pdo, 'login_account', $username, false);
}

function security_kiosk_begin(PDO $pdo): array {
  $limits = security_login_limits($pdo);
  $source = security_take_attempt($pdo, 'kiosk_burst', '', true, $limits['kiosk_per_minute'], 60, true);
  if ($source['locked']) return $source + ['kind' => 'ip'];
  return security_take_attempt($pdo, 'kiosk_browser', security_browser_id(), false, $limits['attempts'], $limits['lock_minutes'] * 60) + ['kind' => 'browser'];
}

function security_kiosk_status(PDO $pdo): array {
  return security_throttle_status($pdo, 'kiosk_browser', security_browser_id(), false) + ['kind' => 'browser'];
}

function security_kiosk_failure(PDO $pdo): array {
  return security_kiosk_status($pdo);
}

function security_kiosk_success(PDO $pdo): void {
  security_throttle_clear($pdo, 'kiosk_browser', security_browser_id(), false);
}

function security_retry_message(array $status): string {
  $seconds = max(1, (int)($status['retry_after'] ?? 1));
  if (($status['kind'] ?? '') === 'ip') {
    return 'Sehr viele Anmeldeanfragen aus diesem Netzwerk. Bitte in etwa ' . $seconds . ' Sekunde(n) erneut versuchen.';
  }
  $target = ($status['kind'] ?? '') === 'browser' ? 'in diesem Browser' : 'für diese Anmeldedaten';
  return 'Zu viele Anmeldeversuche ' . $target . '. Bitte in etwa ' . max(1, (int)ceil($seconds / 60)) . ' Minute(n) erneut versuchen.';
}
