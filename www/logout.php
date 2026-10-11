<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo 'Method Not Allowed';
  exit;
}

require_once __DIR__ . '/lib/csrf.php';
csrf_check();

$to = $_POST['to'] ?? '';

if ($to === 'kiosk' && !empty($_SESSION['via_kiosk'])) {
  kiosk_reset_student(db());
  session_regenerate_id(true);
} else {
  logout_now();
}

$target = $to === 'kiosk' ? '/kiosk.php' : '/index.php';
redirect(url($target));
