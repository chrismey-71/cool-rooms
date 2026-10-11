<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/branding.php';

$branding = branding_settings();
$appName = (string)$branding['brand_title'];
$nameLength = function_exists('mb_strlen') ? mb_strlen($appName, 'UTF-8') : strlen($appName);
$shortName = $nameLength > 18 ? mb_substr($appName, 0, 18, 'UTF-8') : $appName;
$base = base_url();
$scope = $base !== '' ? ($base . '/') : '/';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
  'name' => $appName,
  'short_name' => $shortName,
  'description' => 'COOL-Rooms-App für Raumwahl, Lehrkraft-Standorte und Kiosk-Nutzung.',
  'lang' => 'de-AT',
  'start_url' => url('/index.php'),
  'scope' => $scope,
  'display' => 'standalone',
  'background_color' => $branding['theme_background'],
  'theme_color' => $branding['theme_primary'],
  'icons' => [
    [
      'src' => url('/assets/icons/icon-192.png?v=' . APP_VERSION),
      'sizes' => '192x192',
      'type' => 'image/png',
      'purpose' => 'any',
    ],
    [
      'src' => url('/assets/icons/icon-512.png?v=' . APP_VERSION),
      'sizes' => '512x512',
      'type' => 'image/png',
      'purpose' => 'any',
    ],
    [
      'src' => url('/assets/icons/icon-512-maskable.png?v=' . APP_VERSION),
      'sizes' => '512x512',
      'type' => 'image/png',
      'purpose' => 'maskable',
    ],
  ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
