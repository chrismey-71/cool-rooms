<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/kiosk_access.php';

function session_idle_timeout(): int {
  static $timeout = null;
  if ($timeout !== null) return $timeout;

  $cfg = app_config();
  $configured = (int)($cfg['session_idle_timeout'] ?? 1800);
  $timeout = $configured > 0 ? $configured : 1800;
  return $timeout;
}

function logout_with_flash(?string $type = null, ?string $message = null): void {
  logout_now();

  if ($type !== null && $message !== null) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    flash_set($type, $message);
  }
}

function enforce_session_timeout(): void {
  if (empty($_SESSION['uid'])) return;

  $now = time();
  $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
  $timeout = session_idle_timeout();

  if ($lastActivity > 0 && ($now - $lastActivity) > $timeout) {
    logout_with_flash('error', 'Sitzung wegen Inaktivität beendet.');
    redirect(url('/index.php?timeout=1'));
  }

  $_SESSION['last_activity'] = $now;
}

function auth_user(): ?array {
  enforce_session_timeout();

  if (empty($_SESSION['uid'])) return null;

  if (!empty($_SESSION['via_kiosk']) && !kiosk_session_grant_valid(db())) {
    kiosk_clear_session_identity();
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    flash_set('error', 'Der Kioskzugang ist nicht mehr gültig. Bitte Kiosk-PIN erneut eingeben.');
    return null;
  }

  static $user = null;
  if ($user !== null) return $user;

  $stmt = db()->prepare("SELECT id, role, first_name, last_name, username, class_name, is_admin, is_active, must_change_password FROM users WHERE id=?");
  $stmt->execute([ (int)$_SESSION['uid'] ]);
  $user = $stmt->fetch() ?: null;
  if ($user && (int)$user['is_active'] !== 1) return null;
  return $user;
}

function is_kiosk_student_session(): bool {
  return !empty($_SESSION['uid']) && !empty($_SESSION['via_kiosk']);
}

function is_admin_user(?array $u): bool {
  if (!$u) return false;
  if ($u['role'] === 'admin') return true;
  if ($u['role'] === 'teacher' && (int)$u['is_admin'] === 1) return true;
  return false;
}

function require_login(): array {
  $u = auth_user();
  if (!$u) redirect(url('/index.php'));

  // Enforce password change if flagged
  $script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? '');
  $root = base_url();
  $pathRel = $root && strpos($script, $root) === 0 ? substr($script, strlen($root)) : $script;

  if (is_kiosk_student_session() && $u['role'] === 'student') {
    $allowed = ['/student/home.php', '/teachers.php', '/kiosk.php', '/logout.php'];
    if (!in_array($pathRel, $allowed, true)) {
      redirect(url('/student/home.php'));
    }
    return $u;
  }

  if ((int)($u['must_change_password'] ?? 0) === 1 && $pathRel !== '/account/password.php') {
    redirect(url('/account/password.php'));
  }

  return $u;
}

function require_admin(): array {
  $u = require_login();
  if (!is_admin_user($u)) {
    http_response_code(403);
    echo "Kein Zugriff (Admin erforderlich).";
    exit;
  }
  return $u;
}

function require_teacher(): array {
  $u = require_login();
  if ($u['role'] !== 'teacher' && !is_admin_user($u)) {
    http_response_code(403);
    echo "Kein Zugriff (Lehrer erforderlich).";
    exit;
  }
  return $u;
}

function require_student(): array {
  $u = require_login();
  if ($u['role'] !== 'student') {
    http_response_code(403);
    echo "Kein Zugriff (Schüler erforderlich).";
    exit;
  }
  return $u;
}

function login_attempt(string $username, string $password): bool {
  $pdo = db();
  $status = security_login_status($pdo, $username);
  if ($status['locked']) {
    password_verify($password, '$2y$10$7EqJtq98hPqEX7fNZaFWoO5QhA1cJ4xj4Q9Q4nK4Zf3Qv4L7nK1Aa');
    $_SESSION['login_retry_after'] = max(1, (int)$status['retry_after']);
    return false;
  }

  $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username=? AND is_active=1");
  $stmt->execute([$username]);
  $row = $stmt->fetch();
  $valid = $row && password_verify($password, (string)$row['password_hash']);
  if (!$valid) {
    if (!$row) {
      password_verify($password, '$2y$10$7EqJtq98hPqEX7fNZaFWoO5QhA1cJ4xj4Q9Q4nK4Zf3Qv4L7nK1Aa');
    }
    $status = security_login_failure($pdo, $username);
    if ($status['locked']) $_SESSION['login_retry_after'] = max(1, (int)$status['retry_after']);
    return false;
  }

  security_login_success($pdo, $username);
  unset($_SESSION['login_retry_after']);

  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$row['id'];
  $_SESSION['last_activity'] = time();
  return true;
}

function logout_now(): void {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();
}
