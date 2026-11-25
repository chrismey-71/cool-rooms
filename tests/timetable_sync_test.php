<?php
declare(strict_types=1);
// Use a disposable copy configured for the dedicated database only.
// COOL_ROOMS_TEST_ROOT=/tmp/copy php tests/timetable_sync_test.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath((string)getenv('COOL_ROOMS_TEST_ROOT'));
if(!$root || $root===realpath(__DIR__.'/..') || !is_file($root.'/www/config.php'))throw new RuntimeException('Set COOL_ROOMS_TEST_ROOT to a disposable application copy.');
require $root.'/www/lib/timetable_sync.php';
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='cool_rooms_timetable_test')throw new RuntimeException('Only cool_rooms_timetable_test is allowed.');
preg_match_all('/CREATE TABLE IF NOT EXISTS [a-z_]+\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',file_get_contents($root.'/www/install.php'),$schema);
foreach($schema[0] as $sql)$pdo->exec($sql);
timetable_schema($pdo);
if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0)throw new RuntimeException('Dedicated database must contain no users.');
function sync_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
function sync_snapshot(PDO $pdo,int $id):string{$s=$pdo->prepare('SELECT data_encrypted FROM teacher_timetables WHERE teacher_id=?');$s->execute([$id]);return (string)$s->fetchColumn();}
$calendar="BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:cron-demo\r\nDTSTART:20261125T080000Z\r\nDTEND:20261125T090000Z\r\nSUMMARY:Demo\r\nLOCATION:H\r\nDESCRIPTION:2Aa DEMO\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$pdo->beginTransaction();
try {
  $insert=$pdo->prepare("INSERT INTO users(id,role,username,password_hash,first_name,last_name) VALUES(?,'teacher',?,'unused','Test','Example')");
  for($id=1;$id<=8;$id++)$insert->execute([$id,'sync-test-'.$id]);
  $base=timetable_defaults(['enabled'=>false,'feed_url'=>'https://demo.webuntis.com/calendar?token=DO-NOT-LOG','feed_user'=>'test-user','feed_password'=>'DO-NOT-LOG-PASSWORD','events'=>[['id'=>'old']],'imported_at'=>'2026-11-24T00:00:00Z']);
  for($id=1;$id<=8;$id++)timetable_save($pdo,$id,$base);
  timetable_save($pdo,2,array_replace($base,['feed_url'=>'']));
  timetable_save($pdo,3,array_replace($base,['auto_refresh'=>false]));
  $pdo->exec("UPDATE users SET role='admin' WHERE id=4");
  $before=sync_snapshot($pdo,1);$calls=0;
  $dry=timetable_sync($pdo,true,function()use(&$calls){$calls++;return '';});
  sync_check($calls===0 && $dry===['eligible'=>5,'updated'=>0,'skipped'=>2,'conflicts'=>0,'failed'=>0],'dry run counts only opted-in URLs of actual teachers, without fetching');
  sync_check(sync_snapshot($pdo,1)===$before,'dry run does not touch encrypted calendar');
  $locationBefore=$pdo->query('SELECT * FROM teacher_locations')->fetchAll();
  $classBefore=$pdo->query('SELECT * FROM teacher_responsibility')->fetchAll();
  $messages=[];$calls=0;
  $result=timetable_sync($pdo,false,function($url,$user,$password)use(&$calls,$calendar,$pdo,$base){
    $calls++;
    sync_check(str_contains($url,'DO-NOT-LOG') && $user==='test-user' && $password==='DO-NOT-LOG-PASSWORD','saved credentials passed only to fetcher');
    if($calls===2)return 'invalid calendar with private URL';
    if($calls===3){timetable_save($pdo,6,array_replace($base,['auto_refresh'=>false]));return $calendar;}
    if($calls===4){$pdo->exec('DELETE FROM teacher_timetables WHERE teacher_id=7');return $calendar;}
    if($calls===5)throw new RuntimeException('PRIVATE URL AND PASSWORD MUST NOT BE LOGGED');
    return $calendar;
  },function($message)use(&$messages){$messages[]=$message;});
  sync_check($result===['eligible'=>5,'updated'=>1,'skipped'=>2,'conflicts'=>2,'failed'=>2],'bad feeds fail independently; edited and deleted calendars are not overwritten');
  $updated=timetable_decode(sync_snapshot($pdo,1));
  sync_check(count($updated['events'])===1 && $updated['events'][0]['subject']==='Demo' && $updated['enabled']===false,'successful refresh replaces events without enabling suggestions');
  sync_check($updated['feed_password']===$base['feed_password'] && $updated['imported_at']!==$base['imported_at'],'credentials retained and import timestamp updated');
  sync_check(sync_snapshot($pdo,5)!=='' && timetable_decode(sync_snapshot($pdo,5))['events']===$base['events'],'invalid calendar preserves previous events');
  sync_check(timetable_decode(sync_snapshot($pdo,6))['auto_refresh']===false && sync_snapshot($pdo,7)==='','concurrent opt-out and deletion survive refresh');
  sync_check(!str_contains(implode(' ',$messages),'PRIVATE') && !str_contains(implode(' ',$messages),'DO-NOT-LOG') && count($messages)===2,'failure logs contain no exception details, credentials or URL');
  sync_check(!str_contains(sync_snapshot($pdo,1),'DO-NOT-LOG') && !str_contains(sync_snapshot($pdo,1),'SUMMARY'),'refreshed calendar remains encrypted');
  sync_check($pdo->query('SELECT * FROM teacher_locations')->fetchAll()===$locationBefore && $pdo->query('SELECT * FROM teacher_responsibility')->fetchAll()===$classBefore,'scheduled refresh never assigns teacher rooms or classes');
  // Empty or cancelled calendars are valid replacements, not fetch failures.
  $empty=timetable_sync($pdo,false,fn()=>"BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
  sync_check($empty['failed']===0 && timetable_decode(sync_snapshot($pdo,1))['events']===[],'valid empty calendar clears stale lessons');
  $write=$pdo->prepare('UPDATE teacher_timetables SET data_encrypted=? WHERE teacher_id=1');$write->execute(['corrupted encrypted value']);
  $corrupt=timetable_sync($pdo,false,fn()=>$calendar);
  sync_check($corrupt['failed']===1 && sync_snapshot($pdo,1)==='corrupted encrypted value','decryption failure does not replace stored data or stop other teachers');
} finally {
  if($pdo->inTransaction())$pdo->rollBack();
}
echo "ALL TIMETABLE SYNC TESTS PASSED\n";
