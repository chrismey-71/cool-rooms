<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function branding_color_fields(): array {
  return [
    'theme_primary' => ['label' => 'Navigation und Hauptaktionen', 'default' => '#2F6F3A', 'group' => 'Grundlayout'],
    'theme_brand' => ['label' => 'Ergänzende Markenfarbe', 'default' => '#478E4F', 'group' => 'Grundlayout'],
    'theme_accent' => ['label' => 'Auswahl und Hervorhebung', 'default' => '#175CD3', 'group' => 'Grundlayout'],
    'theme_background' => ['label' => 'Seitenhintergrund', 'default' => '#F6F7FB', 'group' => 'Grundlayout'],
    'theme_card' => ['label' => 'Karten und Eingabeflächen', 'default' => '#FFFFFF', 'group' => 'Grundlayout'],
    'theme_text' => ['label' => 'Haupttext', 'default' => '#101828', 'group' => 'Grundlayout'],
    'theme_muted' => ['label' => 'Hinweistext', 'default' => '#667085', 'group' => 'Grundlayout'],
    'theme_border' => ['label' => 'Rahmenlinien', 'default' => '#E4E7EC', 'group' => 'Grundlayout'],
    'theme_success' => ['label' => 'Erfolg und anwesend', 'default' => '#087443', 'group' => 'Status und Hinweise'],
    'theme_warning' => ['label' => 'Warnung und Kiosk', 'default' => '#F79009', 'group' => 'Status und Hinweise'],
    'theme_danger' => ['label' => 'Löschen und Gefahr', 'default' => '#F7A1A5', 'group' => 'Status und Hinweise'],
    'theme_absent' => ['label' => 'Nicht anwesend', 'default' => '#B42318', 'group' => 'Status und Hinweise'],
    'theme_admin_rooms' => ['label' => 'Raumsteuerung', 'default' => '#0F766E', 'group' => 'Admin-Kategorien'],
    'theme_admin_classes' => ['label' => 'Klassenorganisation', 'default' => '#B54708', 'group' => 'Admin-Kategorien'],
    'theme_admin_overview' => ['label' => 'Lehrkräfte und Überblick', 'default' => '#344054', 'group' => 'Admin-Kategorien'],
    'theme_teacher_day' => ['label' => 'Aktueller Tag', 'default' => '#2F6F3A', 'group' => 'Lehreransicht'],
    'theme_teacher_tools' => ['label' => 'Kiosk, Live und Passwörter', 'default' => '#175CD3', 'group' => 'Lehreransicht'],
    'theme_student_current' => ['label' => 'Aktuell gewählter Raum', 'default' => '#175CD3', 'group' => 'Schüleransicht'],
    'theme_student_classroom' => ['label' => 'Klassenraum', 'default' => '#2F6F3A', 'group' => 'Schüleransicht'],
  ];
}

function branding_valid_color(string $value, string $default): string {
  $value = strtoupper(trim($value));
  return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : strtoupper($default);
}

function branding_default_logo(): string {
  return '/assets/cool-rooms-logo.svg';
}

function branding_resolve_logo(string $logoPath): string {
  $default = branding_default_logo();
  $logoPath = trim($logoPath);
  if (
    preg_match('#^/assets/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D', $logoPath) !== 1 ||
    strpos($logoPath, '..') !== false
  ) {
    return $default;
  }

  $fullPath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
  return is_file($fullPath) ? $logoPath : $default;
}

function branding_default_title(array $cfg): string {
  $configured = trim((string)($cfg['app_title'] ?? $cfg['app_name'] ?? ''));
  if ($configured === '' || in_array($configured, ['COOL Raumfinder', 'COOLer Raumfinder'], true)) {
    return 'COOL Rooms';
  }
  return $configured;
}

function branding_defaults(): array {
  $cfg = app_config();
  $defaults = [
    'brand_title' => branding_default_title($cfg),
    'brand_subtitle' => trim((string)($cfg['app_subtitle'] ?? 'Buche deinen Raum')),
    'brand_logo' => branding_default_logo(),
  ];
  foreach (branding_color_fields() as $key => $field) {
    $defaults[$key] = $field['default'];
  }
  return $defaults;
}

function branding_settings(?PDO $pdo = null): array {
  static $cache = null;
  $useCache = $pdo === null;
  if ($cache !== null && $useCache) {
    return $cache;
  }

  $settings = branding_defaults();
  try {
    $pdo = $pdo ?? db();
    $keys = array_keys($settings);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT k, v FROM app_settings WHERE k IN ($placeholders)");
    $stmt->execute($keys);
    foreach ($stmt->fetchAll() as $row) {
      $key = (string)($row['k'] ?? '');
      if (array_key_exists($key, $settings)) {
        $settings[$key] = (string)($row['v'] ?? '');
      }
    }
  } catch (Throwable $e) {
    // Installation and older databases must remain usable with the defaults.
  }

  $settings['brand_title'] = trim((string)$settings['brand_title']) ?: 'COOL Rooms';
  $settings['brand_subtitle'] = trim((string)$settings['brand_subtitle']);
  $settings['brand_logo'] = branding_resolve_logo((string)$settings['brand_logo']);
  foreach (branding_color_fields() as $key => $field) {
    $settings[$key] = branding_valid_color((string)$settings[$key], $field['default']);
  }

  if ($useCache) {
    $cache = $settings;
  }
  return $settings;
}

function branding_save_setting(PDO $pdo, string $key, string $value): void {
  $stmt = $pdo->prepare('INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
  $stmt->execute([$key, $value]);
}

function branding_css_variables(array $branding): string {
  $map = [
    'theme_primary' => '--theme-primary',
    'theme_brand' => '--theme-brand',
    'theme_accent' => '--theme-accent',
    'theme_background' => '--theme-background',
    'theme_card' => '--theme-card',
    'theme_text' => '--theme-text',
    'theme_muted' => '--theme-muted',
    'theme_border' => '--theme-border',
    'theme_success' => '--theme-success',
    'theme_warning' => '--theme-warning',
    'theme_danger' => '--theme-danger',
    'theme_absent' => '--theme-absent',
    'theme_admin_rooms' => '--theme-admin-rooms',
    'theme_admin_classes' => '--theme-admin-classes',
    'theme_admin_overview' => '--theme-admin-overview',
    'theme_teacher_day' => '--theme-teacher-day',
    'theme_teacher_tools' => '--theme-teacher-tools',
    'theme_student_current' => '--theme-student-current',
    'theme_student_classroom' => '--theme-student-classroom',
  ];
  $parts = [];
  foreach ($map as $key => $variable) {
    $parts[] = $variable . ':' . $branding[$key];
  }
  return ':root{' . implode(';', $parts) . '}';
}
