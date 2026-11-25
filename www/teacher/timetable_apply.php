<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/csrf.php';
require_once __DIR__.'/../lib/timetable.php';
require_once __DIR__.'/../lib/events.php';
$u=require_teacher();
if($u['role']!=='teacher'){http_response_code(403);exit('Persönliche Stundenplanübernahmen sind nur für Lehrkräfte verfügbar.');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
csrf_check();
$pdo=db(); $teacher=(int)$u['id'];
try {
  $data=timetable_load($pdo,$teacher);
  if(!$data['enabled']) throw new RuntimeException('Bitte die Stundenplan-Vorauswahl zuerst aktivieren.');
  $id=(string)($_POST['event_id'] ?? '');
  $events=array_values(array_filter($data['events'],fn($e)=>hash_equals($e['id'],$id)));
  if(count($events)!==1) throw new RuntimeException('Termin nicht gefunden.');
  $mode=(string)($_POST['mode'] ?? 'both');
  if(!in_array($mode,['room','class','both'],true)) throw new RuntimeException('Ungültige Auswahl.');
  $targets=timetable_targets($pdo,$data,$events);
  if(($mode!=='class' && !$targets['room']) || ($mode!=='room' && $targets['class']==='')) throw new RuntimeException('Die Administration muss die Kürzel dieses Termins zuerst eindeutig zuordnen.');
  $typeLength=ensure_events_schema($pdo);
  $pdo->beginTransaction();
  try {
    if($mode!=='class') {
      $st=$pdo->prepare('INSERT INTO teacher_locations(teacher_id,room_id) VALUES(?,?) ON DUPLICATE KEY UPDATE room_id=VALUES(room_id)');$st->execute([$teacher,$targets['room']]);
      $st=$pdo->prepare('SELECT name FROM rooms WHERE id=?');$st->execute([$targets['room']]);
      emit_event('teacher_location',['teacher_id'=>$teacher,'teacher_name'=>trim($u['first_name'].' '.$u['last_name']),'room_id'=>$targets['room'],'room_name'=>(string)$st->fetchColumn()],$typeLength);
    }
    if($mode!=='room') {
      $st=$pdo->prepare('INSERT INTO teacher_responsibility(teacher_id,class_name) VALUES(?,?) ON DUPLICATE KEY UPDATE class_name=VALUES(class_name)');$st->execute([$teacher,$targets['class']]);
      $st=$pdo->prepare('INSERT INTO teacher_class_calendar_selection(teacher_id,class_name,event_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE class_name=VALUES(class_name),event_id=VALUES(event_id)');$st->execute([$teacher,$targets['class'],$id]);
      emit_event('teacher_responsible_class_change',['actor_id'=>$teacher,'actor_role'=>'teacher','new_class'=>$targets['class']],$typeLength);
    }
    emit_event('teacher_timetable_apply',['actor_id'=>$teacher,'actor_role'=>'teacher','mode'=>$mode,'room_id'=>$mode==='class'?null:$targets['room'],'class_name'=>$mode==='room'?null:$targets['class']],$typeLength);
    $pdo->commit();
  } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  flash_set('success',$mode==='room'?'Raum übernommen.':($mode==='class'?'Klasse übernommen.':'Raum und Klasse übernommen.'));
} catch(Throwable $e) {
  flash_set('error',$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Übernahme fehlgeschlagen. Bitte erneut versuchen.');
}
redirect(url('/teacher/home.php'));
