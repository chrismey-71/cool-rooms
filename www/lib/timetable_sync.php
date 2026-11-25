<?php
declare(strict_types=1);
require_once __DIR__.'/timetable.php';

/** Fetch outside a transaction; replace only the exact, still-current encrypted snapshot. */
function timetable_sync(PDO $pdo, bool $dryRun=false, ?callable $fetch=null, ?callable $report=null): array {
  $fetch=$fetch ?? 'timetable_fetch';
  $counts=['eligible'=>0,'updated'=>0,'skipped'=>0,'conflicts'=>0,'failed'=>0];
  $ids=$pdo->query("SELECT t.teacher_id FROM teacher_timetables t JOIN users u ON u.id=t.teacher_id WHERE u.role='teacher' ORDER BY t.teacher_id")->fetchAll(PDO::FETCH_COLUMN);
  $read=$pdo->prepare("SELECT t.data_encrypted FROM teacher_timetables t JOIN users u ON u.id=t.teacher_id WHERE t.teacher_id=? AND u.role='teacher'");
  $write=$pdo->prepare("UPDATE teacher_timetables SET data_encrypted=? WHERE teacher_id=? AND BINARY data_encrypted=? AND EXISTS(SELECT 1 FROM users WHERE id=teacher_timetables.teacher_id AND role='teacher')");
  foreach($ids as $id) {
    try {
      $read->execute([(int)$id]);$snapshot=$read->fetchColumn();
      if($snapshot===false){$counts['skipped']++;continue;}
      $data=timetable_decode((string)$snapshot);
      if($data['feed_url']==='' || !$data['auto_refresh']){$counts['skipped']++;continue;}
      $counts['eligible']++;
      if($dryRun)continue;
      $ics=$fetch($data['feed_url'],$data['feed_user'],$data['feed_password']);
      $data=timetable_import_data($data,$ics);
      $encrypted=secret_encrypt(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
      $write->execute([$encrypted,(int)$id,$snapshot]);
      if($write->rowCount()===1)$counts['updated']++;
      else $counts['conflicts']++;
    } catch(Throwable $e) {
      $counts['failed']++;
      // Never disclose remote content, personal URLs, passwords or database details.
      if($report)$report('Lehrkraft-ID '.(int)$id.': Aktualisierung fehlgeschlagen; bisheriger Kalender unveraendert.');
    }
  }
  return $counts;
}
