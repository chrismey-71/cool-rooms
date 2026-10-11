<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function h(?string $s): string {
  $s = $s ?? '';
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
  header("Location: $url");
  exit;
}

/**
 * Robust: returns the app root path (without trailing slash), e.g. '' or '/cool-rooms'
 * Fixes "double folder" issues by stripping known subfolders from SCRIPT_NAME.
 */
function base_url(): string {
  $cfg = app_config();
  if (!empty($cfg['base_url'])) return rtrim($cfg['base_url'], '/');

  $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
  if ($script === '') return '';

  // Find earliest occurrence of known subfolders and cut there
  $folders = ['/admin/', '/teacher/', '/student/', '/account/', '/api/', '/assets/', '/lib/', '/partials/'];
  $pos = null;
  foreach ($folders as $f) {
    $p = strpos($script, $f);
    if ($p !== false) $pos = ($pos === null) ? $p : min($pos, $p);
  }

  if ($pos !== null) {
    $root = substr($script, 0, $pos);
  } else {
    $root = dirname($script);
  }

  $root = rtrim(str_replace('\\','/',$root), '/');
  if ($root === '' || $root === '.') return '';
  if ($root === '/') return '';
  return $root;
}

function url(string $path): string {
  $b = base_url();
  if ($path === '') return $b ?: '/';
  if ($path[0] !== '/') $path = '/' . $path;
  // ensure single slash join
  if ($b === '') return $path;
  return $b . $path;
}

function flash_set(string $type, string $msg): void {
  $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array {
  if (!isset($_SESSION['flash'])) return null;
  $f = $_SESSION['flash'];
  unset($_SESSION['flash']);
  return $f;
}
