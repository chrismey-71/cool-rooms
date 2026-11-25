<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
class TimetableCronSetupException extends RuntimeException {}
date_default_timezone_set('Europe/Vienna');
umask(0077);
$help="Aufruf: php sync_timetables.php [--dry-run] [--webroot=/absoluter/Pfad/public_html]\n"
  ."Aktualisiert gespeicherte WebUntis-Adressen mit aktiver automatischer Aktualisierung.\n"
  ."--dry-run: nur zaehlen, keine Abrufe oder Kalenderaenderungen.\n"
  ."Ohne --webroot: public_html/ oder www/ neben cron/ (genau eine Konfiguration).\n";
$dryRun=false;$webroot=null;
foreach(array_slice($argv,1) as $arg){
  if($arg==='--help'){fwrite(STDOUT,$help);exit(0);}
  if($arg==='--dry-run' && !$dryRun){$dryRun=true;continue;}
  if(str_starts_with($arg,'--webroot=') && $webroot===null){$webroot=substr($arg,10);continue;}
  fwrite(STDERR,"Unbekannte oder doppelte Option.\n".$help);exit(2);
}
try {
  if($webroot===null){
    $roots=array_values(array_filter([__DIR__.'/../public_html',__DIR__.'/../www'],fn($p)=>is_file($p.'/config.php')));
    if(count($roots)!==1)throw new TimetableCronSetupException('Konfiguration nicht eindeutig gefunden. --webroot=/absoluter/Pfad/public_html angeben.');
    $webroot=$roots[0];
  } elseif($webroot==='' || $webroot[0]!=='/')throw new TimetableCronSetupException('--webroot muss ein absoluter Verzeichnispfad sein.');
  $webroot=realpath($webroot);
  if(!$webroot || !is_file($webroot.'/config.php') || !is_file($webroot.'/lib/timetable_sync.php'))throw new TimetableCronSetupException('Im angegebenen Webverzeichnis fehlen config.php oder lib/timetable_sync.php. Dateien gemeinsam aktualisieren; config.php erhalten.');
  $lock=fopen(__DIR__.'/.sync_timetables.lock','c');
  if($lock===false)throw new TimetableCronSetupException('Sperrdatei in cron/ kann nicht angelegt werden. Schreibrechte fuer den Cron-Benutzer pruefen.');
  if(!flock($lock,LOCK_EX|LOCK_NB)){
    fwrite(STDOUT,'['.date(DATE_ATOM)."] WebUntis-Abruf bereits aktiv; parallelen Aufruf uebersprungen.\n");exit(0);
  }
  require_once $webroot.'/lib/timetable_sync.php';
  timetable_schema(db());
  $counts=timetable_sync(db(),$dryRun,null,fn($message)=>fwrite(STDERR,'['.date(DATE_ATOM).'] '.$message.PHP_EOL));
  fwrite(STDOUT,'['.date(DATE_ATOM).'] '.($dryRun?'WebUntis-Vorschau (ohne Abruf)':'WebUntis-Aktualisierung abgeschlossen').': '.json_encode($counts,JSON_THROW_ON_ERROR).PHP_EOL);
  exit($counts['failed']>0?1:0);
} catch(Throwable $e) {
  // Exceptions from PDO, decryption or remote access must not reach cron mail or logs.
  $message=$e instanceof TimetableCronSetupException?$e->getMessage():'Webroot, Konfiguration, Schreibrechte und Datenbankverbindung pruefen; keine Zugangsdaten protokolliert.';
  fwrite(STDERR,'['.date(DATE_ATOM).'] WebUntis-Aktualisierung fehlgeschlagen: '.$message.PHP_EOL);
  exit(1);
}
