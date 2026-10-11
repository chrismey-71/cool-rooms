<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function security_setting_int(PDO $pdo, string $key, int $default, int $min, int $max): int {
  $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
  $stmt->execute([$key]);
  $value = $stmt->fetchColumn();
  $number = $value === false ? $default : (int)$value;
  return max($min, min($max, $number));
}

function security_login_limits(PDO $pdo): array {
  return [
    'attempts' => security_setting_int($pdo, 'auth_max_attempts', 5, 3, 20),
    'lock_minutes' => security_setting_int($pdo, 'auth_lock_minutes', 15, 1, 30),
  ];
}

function security_client_ip(): string {
  $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
  return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function security_ensure_throttle_schema(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS auth_attempts (
      bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
      scope VARCHAR(24) NOT NULL,
      failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      locked_until DATETIME NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_auth_attempts_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $ready = true;
}

function security_throttle_bucket(string $scope, string $identifier = '', bool $includeIp = true): string {
  $normalized = function_exists('mb_strtolower')
    ? mb_strtolower(trim($identifier), 'UTF-8')
    : strtolower(trim($identifier));
  $source = $includeIp ? security_client_ip() : '';
  return hash('sha256', $scope . "\0" . $normalized . "\0" . $source);
}

function security_throttle_status(PDO $pdo, string $scope, string $identifier = '', bool $includeIp = true): array {
  security_ensure_throttle_schema($pdo);
  $stmt = $pdo->prepare("
    SELECT failures,
           CASE WHEN locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP() THEN 1 ELSE 0 END AS is_locked,
           GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until)) AS retry_after
    FROM auth_attempts
    WHERE bucket_hash=?
    LIMIT 1
  ");
  $stmt->execute([security_throttle_bucket($scope, $identifier, $includeIp)]);
  $row = $stmt->fetch();
  return [
    'locked' => $row && (int)$row['is_locked'] === 1,
    'retry_after' => $row ? (int)$row['retry_after'] : 0,
  ];
}

function security_throttle_failure(PDO $pdo, string $scope, string $identifier = '', bool $includeIp = true): array {
  security_ensure_throttle_schema($pdo);
  $limits = security_login_limits($pdo);
  $bucket = security_throttle_bucket($scope, $identifier, $includeIp);

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("SELECT failures, locked_until, locked_until > UTC_TIMESTAMP() AS is_locked FROM auth_attempts WHERE bucket_hash=? FOR UPDATE");
    $stmt->execute([$bucket]);
    $row = $stmt->fetch();

    if ($row && (int)$row['is_locked'] === 1) {
      $failures = (int)$row['failures'];
      $lockedUntil = (string)$row['locked_until'];
    } else {
      $failures = ($row ? (int)$row['failures'] : 0) + 1;
      $lockedUntil = $failures >= $limits['attempts']
        ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $limits['lock_minutes'] . ' minutes')->format('Y-m-d H:i:s')
        : null;
    }

    $stmt = $pdo->prepare("
      INSERT INTO auth_attempts (bucket_hash, scope, failures, locked_until)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE scope=VALUES(scope), failures=VALUES(failures), locked_until=VALUES(locked_until)
    ");
    $stmt->execute([$bucket, $scope, $failures, $lockedUntil]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  return security_throttle_status($pdo, $scope, $identifier, $includeIp);
}

function security_throttle_clear(PDO $pdo, string $scope, string $identifier = '', bool $includeIp = true): void {
  security_ensure_throttle_schema($pdo);
  $stmt = $pdo->prepare("DELETE FROM auth_attempts WHERE bucket_hash=?");
  $stmt->execute([security_throttle_bucket($scope, $identifier, $includeIp)]);
}

function security_login_status(PDO $pdo, string $username): array {
  $source = security_throttle_status($pdo, 'login_source');
  $account = security_throttle_status($pdo, 'login_account', $username, false);
  return [
    'locked' => $source['locked'] || $account['locked'],
    'retry_after' => max($source['retry_after'], $account['retry_after']),
  ];
}

function security_login_failure(PDO $pdo, string $username): array {
  $source = security_throttle_failure($pdo, 'login_source');
  $account = security_throttle_failure($pdo, 'login_account', $username, false);
  return [
    'locked' => $source['locked'] || $account['locked'],
    'retry_after' => max($source['retry_after'], $account['retry_after']),
  ];
}

function security_login_success(PDO $pdo, string $username): void {
  security_throttle_clear($pdo, 'login_source');
  security_throttle_clear($pdo, 'login_account', $username, false);
}

function security_kiosk_status(PDO $pdo): array {
  $source = security_throttle_status($pdo, 'kiosk_source');
  $global = security_throttle_status($pdo, 'kiosk_global', '', false);
  return [
    'locked' => $source['locked'] || $global['locked'],
    'retry_after' => max($source['retry_after'], $global['retry_after']),
  ];
}

function security_kiosk_failure(PDO $pdo): array {
  $source = security_throttle_failure($pdo, 'kiosk_source');
  $global = security_throttle_failure($pdo, 'kiosk_global', '', false);
  return [
    'locked' => $source['locked'] || $global['locked'],
    'retry_after' => max($source['retry_after'], $global['retry_after']),
  ];
}

function security_kiosk_success(PDO $pdo): void {
  security_throttle_clear($pdo, 'kiosk_source');
  security_throttle_clear($pdo, 'kiosk_global', '', false);
}
