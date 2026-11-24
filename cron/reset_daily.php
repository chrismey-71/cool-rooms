<?php
declare(strict_types=1);

// This entry point must never accept web requests, even if uploaded into a webroot.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

date_default_timezone_set('Europe/Vienna');
$args = array_slice($argv, 1);
if ($args === ['--help']) {
  fwrite(STDOUT, "Aufruf: php reset_daily.php [--dry-run|--help]\nOhne Option: Tagesdaten zuruecksetzen. --dry-run: nur betroffene Anzahlen anzeigen.\n");
  exit(0);
}
if ($args !== [] && $args !== ['--dry-run']) {
  fwrite(STDERR, "Unbekannte Option. Aufruf: php reset_daily.php [--dry-run|--help]\n");
  exit(2);
}

try {
  if (!is_file(__DIR__ . '/../www/config.php')) {
    throw new RuntimeException('Konfiguration fehlt. cron/ muss neben www/ liegen; www/config.php muss erhalten bleiben.');
  }
  require_once __DIR__ . '/../www/lib/daily_reset.php';
  $dryRun = $args === ['--dry-run'];
  $counts = $dryRun ? daily_reset_counts(db()) : reset_all_assignments([
    'id' => 0,
    'role' => 'system',
    'name' => 'Automatischer Tagesreset',
  ], 'cron');
  fwrite(STDOUT, '[' . date(DATE_ATOM) . '] ' . ($dryRun ? 'Vorschau (keine Aenderungen)' : 'Tagesreset erfolgreich') . ': '
    . json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
  exit(0);
} catch (Throwable $e) {
  fwrite(STDERR, '[' . date(DATE_ATOM) . '] Tagesreset fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
