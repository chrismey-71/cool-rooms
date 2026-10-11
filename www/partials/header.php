<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/version.php';
require_once __DIR__ . '/../lib/branding.php';

$u = auth_user();
$cfg = app_config();
$branding = branding_settings();
$f = flash_get();

$script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? '');
$root = base_url();
$pathRel = $root && strpos($script, $root) === 0 ? substr($script, strlen($root)) : $script;
$isKioskStudentSession = $u && ($u['role'] ?? '') === 'student' && !empty($_SESSION['via_kiosk']) && !empty($_SESSION['uid']);
$brandTarget = $isKioskStudentSession ? '/student/home.php' : ($u ? '/dashboard.php' : '/index.php');

function nav_link(string $href, string $label, string $pathRel, string $extraClass = ''): string {
  $active = ($pathRel === $href) || (str_starts_with($pathRel, rtrim($href, '/')) && $href !== '/index.php' && $href !== '/dashboard.php');
  $cls = trim(($active ? 'active ' : '') . $extraClass);
  return '<a class="'.$cls.'" href="'.h(url($href)).'">'.h($label).'</a>';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= h((string)$branding['theme_primary']) ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="<?= h((string)$branding['brand_title']) ?>">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="format-detection" content="telephone=no">
  <title><?= h((string)$branding['brand_title']) ?></title>
  <link rel="manifest" href="<?= h(url('/manifest.php?v=' . APP_VERSION)) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= h(url('/assets/icons/apple-touch-icon.png?v=' . APP_VERSION)) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= h(url('/assets/icons/favicon-32.png?v=' . APP_VERSION)) ?>">
  <link rel="stylesheet" href="<?= h(url('/assets/styles.css?v=' . APP_VERSION)) ?>">
  <style id="brand-theme"><?= branding_css_variables($branding) ?></style>
  <script defer src="<?= h(url('/assets/app.js?v=' . APP_VERSION)) ?>"></script>
</head>
<body class="<?= $isKioskStudentSession ? 'kiosk-session' : '' ?>"
      <?= $isKioskStudentSession ? 'data-kiosk-timeout-url="'.h(url('/kiosk.php')).'" data-kiosk-timeout-action="reset_student" data-kiosk-timeout-csrf="'.h(csrf_token()).'" data-kiosk-timeout-ms="45000"' : '' ?>>
<header class="topbar">
  <div class="wrap">
    <div class="brand">
      <a class="brandlink" href="<?= h(url($brandTarget)) ?>">
        <img class="brandlogo" src="<?= h(url((string)$branding['brand_logo'])) ?>" alt="<?= h(((string)$branding['brand_subtitle'] ?: (string)$branding['brand_title']) . ' Logo') ?>">
        <span class="brandtext">
          <span class="brandtitle"><?= h((string)$branding['brand_title']) ?></span>
          <?php if ((string)$branding['brand_subtitle'] !== ''): ?>
            <span class="brandsub"><?= h((string)$branding['brand_subtitle']) ?></span>
          <?php endif; ?>
        </span>
      </a>
    </div>

    <button class="burger" type="button" aria-label="Menü" aria-expanded="false" aria-controls="mainnav" data-burger>
      <span></span><span></span><span></span>
    </button>

    <nav class="nav" id="mainnav">
      <?php if (!$u): ?>
        <?= nav_link('/index.php', 'Login', $pathRel) ?>
        <?= nav_link('/kiosk.php', 'Kiosk', $pathRel) ?>
      <?php elseif ($isKioskStudentSession): ?>
        <?= nav_link('/student/home.php', 'Raumwahl', $pathRel) ?>
        <?= nav_link('/teachers.php', 'Lehrer finden', $pathRel) ?>
        <form method="post" action="<?= h(url('/kiosk.php')) ?>" class="nav-action">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset_student">
          <button class="kiosk-nav <?= $pathRel === '/kiosk.php' ? 'active' : '' ?>" type="submit">Kiosk</button>
        </form>
        <span class="userpill">
          <span class="badge">Kiosk</span>
          <?= h($u['first_name'].' '.$u['last_name']) ?>
        </span>
      <?php else: ?>
        <?= nav_link('/dashboard.php', 'Dashboard', $pathRel) ?>
        <?= nav_link('/teachers.php', 'Lehrer finden', $pathRel) ?>
        <?= nav_link('/account/password.php', 'Passwort', $pathRel) ?>
        <?= nav_link('/kiosk.php', 'Kiosk', $pathRel) ?>
        <form method="post" action="<?= h(url('/logout.php')) ?>" class="nav-action">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <button type="submit" class="nav-logout">Logout</button>
        </form>
        <span class="userpill">
          <span class="badge"><?= h($u['role']) ?></span>
          <?= h($u['first_name'].' '.$u['last_name']) ?>
        </span>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="wrap">
  <?php if ($f): ?>
    <div class="flash <?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endif; ?>
