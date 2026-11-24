<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Capture lightweight device metadata for audit / kiosk traceability.
 * Note: IP + User-Agent can be personal data (GDPR). Keep retention short (events are pruned).
 */

function events_client_ip(): string {
  // Prefer the left-most X-Forwarded-For if present (only trustworthy if your proxy sets it correctly).
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim((string)($parts[0] ?? ''));
    if ($ip !== '') return $ip;
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function events_user_agent(): string {
  return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function events_device_os(string $ua): string {
  $u = strtolower($ua);
  if (strpos($u, 'iphone') !== false || strpos($u, 'ipad') !== false || strpos($u, 'ipod') !== false) return 'iOS';
  if (strpos($u, 'android') !== false) return 'Android';
  if (strpos($u, 'windows') !== false) return 'Windows';
  if (strpos($u, 'mac os x') !== false || strpos($u, 'macintosh') !== false) return 'macOS';
  if (strpos($u, 'linux') !== false) return 'Linux';
  return 'unknown';
}

function events_device_type(string $ua): string {
  $u = strtolower($ua);
  if (strpos($u, 'ipad') !== false || strpos($u, 'tablet') !== false) return 'tablet';
  if (strpos($u, 'mobile') !== false || strpos($u, 'iphone') !== false || strpos($u, 'android') !== false) return 'mobile';
  return 'desktop';
}

function events_client_id(): string {
  if (PHP_SAPI === 'cli') return '';
  $cookieName = 'cr_device_id';
  $id = (string)($_COOKIE[$cookieName] ?? '');
  if ($id !== '' && preg_match('/^[a-f0-9]{32}$/', $id)) return $id;

  // Generate a stable per-browser device id (best-effort; if headers already sent, it won't persist)
  try {
    $id = bin2hex(random_bytes(16));
  } catch (Throwable $e) {
    $id = substr(md5((string)microtime(true) . (string)mt_rand()), 0, 32);
  }

  if (!headers_sent()) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($cookieName, $id, [
      'expires' => time() + 3600 * 24 * 365,
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }

  return $id;
}

function ensure_events_schema(PDO $pdo): int {
  try {
    $stmt = $pdo->prepare("
      SELECT CHARACTER_MAXIMUM_LENGTH
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'events'
        AND COLUMN_NAME = 'type'
      LIMIT 1
    ");
    $stmt->execute();
    $length = (int)($stmt->fetchColumn() ?: 0);
    if ($length > 0 && $length < 80) {
      $pdo->exec("ALTER TABLE events MODIFY COLUMN type VARCHAR(80) NOT NULL");
      return 80;
    }
    return $length > 0 ? $length : 80;
  } catch (Throwable $e) {
    return 30;
  }
}

function emit_event(string $type, array $payload = [], ?int $knownTypeMaxLength = null): void {
  $pdo = db();
  // Transactional callers prepare the schema before BEGIN (ALTER implicitly commits).
  $typeMaxLength = $knownTypeMaxLength ?? ensure_events_schema($pdo);
  if ($typeMaxLength > 0 && strlen($type) > $typeMaxLength) {
    $type = substr($type, 0, $typeMaxLength);
  }

  // --- Actor snapshot ("Ausgeführt von") ---
  // Store a small human-readable snapshot of who executed the action.
  // This keeps event reports understandable even if accounts are later renamed/deleted.
  // NOTE: Never store passwords/PINs here.
  if (empty($payload['actor_role']) && !empty($_SESSION['uid'])) {
    static $actorCache = null;
    if ($actorCache === null) {
      try {
        $st = $pdo->prepare("SELECT id, role, first_name, last_name, username FROM users WHERE id=? LIMIT 1");
        $st->execute([(int)$_SESSION['uid']]);
        $actorCache = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) {
        $actorCache = [];
      }
    }

    if (!empty($actorCache)) {
      $payload['actor_id'] = (int)($actorCache['id'] ?? 0);
      $payload['actor_role'] = (string)($actorCache['role'] ?? '');
      $payload['actor_username'] = (string)($actorCache['username'] ?? '');
      $payload['actor_first_name'] = (string)($actorCache['first_name'] ?? '');
      $payload['actor_last_name']  = (string)($actorCache['last_name'] ?? '');
      $payload['actor_name'] = trim($payload['actor_last_name'] . ' ' . $payload['actor_first_name']);
    }
  }

  // Kiosk actions may not have a logged-in user.
  if (empty($payload['actor_role']) && !empty($_SESSION['via_kiosk'])) {
    $payload['actor_role'] = 'kiosk';
    $payload['actor_name'] = 'Kiosk';
  }

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $ip = events_client_ip();
  $ua = events_user_agent();
  $os = events_device_os($ua);
  $dev = events_device_type($ua);
  $cid = events_client_id();

  $viaKiosk = (!empty($_SESSION['via_kiosk'])) ? 1 : 0;
  $kioskScopeType  = (string)($_SESSION['kiosk_scope_type'] ?? '');
  $kioskScopeClass = (string)($_SESSION['kiosk_scope_class'] ?? '');

  // Backward compatible insert: try extended columns, fallback if DB not migrated yet
  try {
    $stmt = $pdo->prepare("
      INSERT INTO events
        (type, payload, client_ip, client_user_agent, client_os, client_device, client_id, via_kiosk, kiosk_scope_type, kiosk_scope_class)
      VALUES
        (?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$type, $json, $ip, $ua, $os, $dev, $cid, $viaKiosk, $kioskScopeType, $kioskScopeClass]);
  } catch (PDOException $e) {
    // Unknown column? fall back to legacy schema
    $stmt = $pdo->prepare("INSERT INTO events (type, payload) VALUES (?,?)");
    $stmt->execute([$type, $json]);
  }

  // prune occasionally (keeps last 7 days)
  if (random_int(1, 100) === 1) {
    $pdo->exec("DELETE FROM events WHERE created_at < (NOW() - INTERVAL 7 DAY)");
  }
}
