<?php
declare(strict_types=1);
require __DIR__.'/../www/lib/timetable.php';
function check_calendar(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
function calendar_fixture(string $extra='',string $start='20261125T074000'):string{
  return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:demo-1\r\nDTSTART;TZID=Europe/Vienna:$start\r\nDTEND;TZID=Europe/Vienna:20261125T092000\r\nSUMMARY:BW\\, Rollenspiel\r\nLOCATION:A\r\nDESCRIPTION:2Aa\\; 2Ab DEMO\r\n$extra"."END:VEVENT\r\nEND:VCALENDAR\r\n";
}
$events=timetable_parse(calendar_fixture());
check_calendar(timetable_defaults([])['enabled']===true,'new timetable enables suggestions by default');
check_calendar(timetable_defaults(['enabled'=>false])['enabled']===false,'saved opt-out remains disabled');
check_calendar(timetable_defaults([])['auto_refresh']===true,'URL calendars default to scheduled refresh');
check_calendar(timetable_defaults(['auto_refresh'=>false])['auto_refresh']===false,'scheduled refresh opt-out is retained');
$fileImport=timetable_import_data(timetable_defaults(['feed_url'=>'https://demo.webuntis.com/calendar']),calendar_fixture(),true);
check_calendar($fileImport['auto_refresh']===false && $fileImport['feed_url']!=='','file upload disables scheduled refresh even with an old stored URL');
check_calendar(timetable_import_data(timetable_defaults([]),calendar_fixture())['auto_refresh']===true,'URL import retains scheduled refresh default');
check_calendar(timetable_import_data($fileImport,calendar_fixture())['auto_refresh']===false,'manual URL update does not silently undo scheduled refresh opt-out');
check_calendar(!timetable_has_import(timetable_defaults([])),'new profile has no completed import');
check_calendar(!timetable_has_import(timetable_defaults(['enabled'=>false])),'saving preferences alone does not count as import');
check_calendar(timetable_has_import(['events'=>[],'imported_at'=>'2026-11-25T09:00:00Z']),'valid empty import still counts as completed');
check_calendar(timetable_has_import(['events'=>$events]),'older calendar without import timestamp counts as imported');
$previewEvents=[];$midnight=new DateTimeImmutable('2026-11-25 00:00:00',new DateTimeZone('Europe/Vienna'));
for($i=0;$i<7;$i++)$previewEvents[]=['id'=>(string)$i,'start'=>$midnight->modify('+'.(7+$i).' hours')->getTimestamp(),'end'=>$midnight->modify('+'.(8+$i).' hours')->getTimestamp()];
$tomorrow=['id'=>'tomorrow','start'=>$midnight->modify('+1 day +8 hours')->getTimestamp(),'end'=>$midnight->modify('+1 day +9 hours')->getTimestamp()];
$previewData=['events'=>array_reverse([...$previewEvents,$tomorrow])];
$previewNow=$midnight->modify('+9 hours +30 minutes')->getTimestamp();
$preview=timetable_preview($previewData,'',null,$previewNow);
check_calendar(array_column($preview['events'],'id')===['2','3','4'] && $preview['total']===7,'dashboard starts with current and next lessons of today, sorted, at most three');
check_calendar($preview['previous']===1 && $preview['next']===3,'arrows move the visible window one lesson');
check_calendar(array_column(timetable_preview($previewData,'2026-11-25',0,$previewNow)['events'],'id')===['0','1','2'],'earlier ended lessons remain reachable');
check_calendar(timetable_preview($previewData,'2026-11-25',999,$previewNow)['offset']===6,'oversized offset is safely clamped');
check_calendar(timetable_preview($previewData,'2026-11-26',null,$previewNow)['events'][0]['id']==='tomorrow','next day has its own lesson window');
check_calendar(timetable_preview($previewData,'2026-11-27',null,$previewNow)['total']===0,'empty day does not silently show another day');
check_calendar(timetable_preview($previewData,'2026-02-30',null,$previewNow)['date']->format('Y-m-d')==='2026-11-25','invalid navigation date falls back to today');
check_calendar(timetable_preview($previewData,'',null,$midnight->modify('+20 hours')->getTimestamp())['offset']===4,'after last lesson the final three lessons are shown');
$localBoundary=['events'=>[['id'=>'local','start'=>strtotime('2026-11-24T23:30:00Z'),'end'=>strtotime('2026-11-25T00:30:00Z')]]];
check_calendar(timetable_preview($localBoundary,'2026-11-25',null,$previewNow)['total']===1,'day selection uses Vienna rather than UTC');
check_calendar(timetable_description('MEYS')===['classes'=>[],'teachers'=>['MEYS']],'teacher-only description does not create a class');
check_calendar(timetable_description('3FSBa\\; 3FSBb MEYS')===['classes'=>['3FSBa','3FSBb'],'teachers'=>['MEYS']],'class list followed by teacher code');
check_calendar(timetable_description('1HLSa\\; 2FSBb BUCH\\; DARW\\; MEYS\\; WALL')===['classes'=>['1HLSa','2FSBb'],'teachers'=>['BUCH','DARW','MEYS','WALL']],'all staff codes remain in teacher list');
check_calendar(timetable_description('MEYS\\; BUCH')===['classes'=>[],'teachers'=>['MEYS','BUCH']],'teacher-only list without classes');
check_calendar(timetable_description('2Aa  LONGCODE\\; XY')===['classes'=>['2Aa'],'teachers'=>['LONGCODE','XY']],'teacher codes are not restricted to four characters');
$legacy=[['classes'=>['2Aa','2Ab'],'teachers'=>['MEYS']],['classes'=>['MEYS'],'teachers'=>[]],['classes'=>['2Aa','SCHU','STRA','URBA','WALL'],'teachers'=>['BUCH']]];
$repaired=timetable_legacy_lists($legacy,['2A']);
check_calendar($repaired[1]['classes']===[] && $repaired[1]['teachers']===['MEYS'],'old teacher-only import repaired without reupload');
check_calendar($repaired[2]['classes']===['2Aa'] && $repaired[2]['teachers']===['BUCH','SCHU','STRA','URBA','WALL'],'old misplaced staff list moved to teacher section');
check_calendar($legacy[1]['classes']===['MEYS'],'legacy display repair leaves source data unchanged');
check_calendar(in_array('SCHU',timetable_legacy_lists($legacy,['SCHU'])[2]['classes'],true),'real existing class name is not silently reclassified');
$firstImport=[['classes'=>['1HLSa']],['classes'=>['MEYS']],['classes'=>['1HLSa','3FSBb','SCHU','STRA','URBA','WALL']]];
$fixedFirst=timetable_legacy_lists($firstImport,['1HLS','3FSB'],['MEYS']);
check_calendar($fixedFirst[1]['classes']===[] && $fixedFirst[1]['teachers']===['MEYS'],'earliest import without any teachers field uses known central teacher aliases');
check_calendar($fixedFirst[2]['classes']===['1HLSa','3FSBb'] && $fixedFirst[2]['teachers']===['SCHU','STRA','URBA','WALL'],'earliest staff meeting without teachers field is repaired');
check_calendar(count($events)===1 && $events[0]['classes']===['2Aa','2Ab'],'two subgroup labels preserved');
check_calendar($events[0]['subject']==='BW, Rollenspiel','iCal text escapes decoded');
$at=new DateTimeImmutable('2026-11-25 07:40:00',new DateTimeZone('Europe/Vienna'));
check_calendar($events[0]['start']===$at->getTimestamp(),'Vienna timezone converted correctly');
check_calendar(count(timetable_current(['events'=>$events],$at->getTimestamp()))===1,'start inclusive');
check_calendar(timetable_current(['events'=>$events],$events[0]['end'])===[],'end exclusive');
check_calendar(timetable_parse(calendar_fixture("STATUS:CANCELLED\r\n"))===[],'cancelled-only import clears old lessons instead of keeping stale proposals');
check_calendar(timetable_parse("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n")===[],'valid empty calendar can replace an old import');
$folded=str_replace('SUMMARY:BW\\, Rollenspiel','SUMMARY:BW\\, Rollen' . "\r\n spiel",calendar_fixture());
check_calendar(timetable_parse($folded)[0]['subject']==='BW, Rollenspiel','folded lines unfolded');
foreach([calendar_fixture('RRULE:FREQ=WEEKLY'."\r\n"),calendar_fixture('', '20260230T074000'),'not a calendar'] as $bad){
  try{timetable_parse($bad);throw new LogicException('Invalid calendar accepted');}catch(RuntimeException $e){check_calendar(true,'invalid or recurring calendar rejected');}
}
$pdo=new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE class_groups(id INTEGER,class_name TEXT,name TEXT,webuntis_code TEXT)');
$pdo->exec('CREATE TABLE app_settings(k TEXT,v TEXT); CREATE TABLE rooms(id INTEGER,name TEXT,is_active INTEGER); CREATE TABLE users(role TEXT,class_name TEXT); INSERT INTO rooms VALUES(1,"Raum A",1),(2,"Raum B",1); INSERT INTO users VALUES("student","2A"),("student","3A")');
$data=['events'=>$events,'rooms'=>['A'=>1],'classes'=>['2Aa'=>'2A','2Ab'=>'2A']];
check_calendar(timetable_targets($pdo,$data,$events)===['room'=>1,'class'=>'2A'],'explicit subgroup mapping to existing whole class');
$data['classes']['2Ab']='3A';
check_calendar(timetable_targets($pdo,$data,$events)['class']==='','ambiguous classes are not selected');
$data['classes']['2Ab']='2A';
$other=$events[0];$other['room']='B';$data['rooms']['B']=2;
check_calendar(timetable_targets($pdo,$data,[$events[0],$other])['room']===0,'simultaneous different rooms remain ambiguous');
$pdo->exec('UPDATE rooms SET is_active=0 WHERE id=1');
check_calendar(timetable_targets($pdo,$data,$events)['room']===0,'inactive rooms are never proposed');
$pdo->exec('UPDATE rooms SET is_active=1 WHERE id=1');
$st=$pdo->prepare('INSERT INTO app_settings(k,v) VALUES(?,?)');
$global=['rooms'=>['A'=>2],'classes'=>['2Aa'=>'3A','2Ab'=>'3A'],'teachers'=>['DEMO'=>7]];
$st->execute(['webuntis_mappings_v1',json_encode($global)]);
$data['_teacher_id']=7;
check_calendar(timetable_targets($pdo,$data,$events)===['room'=>2,'class'=>'3A'],'central room and class aliases override personal maps');
$pdo->exec('UPDATE rooms SET is_active=0 WHERE id=2');
check_calendar(timetable_targets($pdo,$data,$events)===['room'=>0,'class'=>'3A'],'stored central alias to inactive room is retained but cannot be applied');
$pdo->exec('UPDATE rooms SET is_active=1 WHERE id=2');
$data['_teacher_id']=8;
check_calendar(timetable_targets($pdo,$data,$events)===['room'=>0,'class'=>''],'another mapped teacher cannot take this event');
$data['_teacher_id']=7;
$pdo->exec("UPDATE app_settings SET v='{}'");
check_calendar(timetable_targets($pdo,$data,$events)===['room'=>0,'class'=>''],'configured central mapping never falls back to stale personal assignments');
foreach(['http://example.com/calendar','https://127.0.0.1/','https://webuntis.com.evil.example/','https://demo.webuntis.com:444/calendar'] as $url){
 try{timetable_fetch($url);throw new LogicException('Unsafe URL accepted');}catch(RuntimeException $e){check_calendar(true,'unsafe feed address rejected before access');}
}
echo "ALL TIMETABLE TESTS PASSED\n";
