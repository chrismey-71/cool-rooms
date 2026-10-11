<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

$u = require_login();

if ((int)($u['must_change_password'] ?? 0) === 1) {
  redirect(url('/account/password.php'));
}


if (is_admin_user($u)) redirect(url('/admin/home.php'));
if ($u['role'] === 'teacher') redirect(url('/teacher/home.php'));
if ($u['role'] === 'student') redirect(url('/student/home.php'));

redirect(url('/index.php'));
