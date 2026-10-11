<?php
declare(strict_types=1);

function app_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;

  $path = __DIR__ . '/../config.php';
  if (!file_exists($path)) {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.' || $dir === '/') {
      $dir = '';
    }
    header('Location: ' . $dir . '/install.php');
    exit;
  }
  $cfg = require $path;
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = app_config();
  $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
  $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}
