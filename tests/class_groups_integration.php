<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath((string)getenv('COOL_ROOMS_TEST_ROOT'));
if(!$root || $root===realpath(__DIR__.'/..') || !is_file($root.'/www/config.php'))throw new RuntimeException('Use a disposable application copy.');
require $root.'/www/lib/class_group_view.php';
$pdo=db();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='cool_rooms_groups_test')throw new RuntimeException('Use cool_rooms_groups_test only.');
preg_match_all('/CREATE TABLE IF NOT EXISTS [a-z_]+\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',file_get_contents($root.'/www/install.php'),$schema);
foreach($schema[0] as $sql)$pdo->exec($sql);
timetable_schema($pdo);
if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0)throw new RuntimeException('Dedicated database must contain no users.');
function integration_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
$pdo->beginTransaction();
try{
  $st=$pdo->prepare('INSERT INTO users(id,role,username,password_hash,first_name,last_name,class_name) VALUES(?,?,?,\'unused\',\'Test\',\'Example\',?)');
  foreach([[1,'teacher','teacher',null],[2,'student','a','1HLS'],[3,'student','b','1HLS'],[4,'student','none','1HLS'],[5,'student','other','2HLS']] as $row)$st->execute($row);
  $pdo->exec("INSERT INTO teacher_responsibility VALUES(1,'1HLS',CURRENT_TIMESTAMP)");
  $a=class_groups_save($pdo,'1HLS',0,'a','1HLSa');$b=class_groups_save($pdo,'1HLS',0,'b','1HLSb');$other=class_groups_save($pdo,'2HLS',0,'a','2HLSa');
  class_groups_assign($pdo,2,$a);class_groups_assign($pdo,3,$b);
  integration_check(class_groups_student_group($pdo,2)===$a,'student assigned to own class group');
  try{class_groups_assign($pdo,2,$other);throw new LogicException('Foreign group accepted');}catch(InvalidArgumentException $e){integration_check(class_groups_student_group($pdo,2)===$a,'foreign class group rejected without changing membership');}
  try{class_groups_save($pdo,'2HLS',0,'duplicate','1HLSa');throw new LogicException('Duplicate accepted');}catch(InvalidArgumentException $e){integration_check(true,'duplicate WebUntis code rejected globally');}
  $id=class_groups_save($pdo,'1HLS',$a,'Team A','1HLSa');integration_check($id===$a && class_groups_student_group($pdo,2)===$a,'renaming group keeps stable membership');
  $now=time();$event=['id'=>hash('sha256','group-a'),'start'=>$now-60,'end'=>$now+1800,'subject'=>'Demo','room'=>'A','classes'=>['1HLSa'],'teachers'=>[],'description_format'=>2];
  timetable_save($pdo,1,timetable_defaults(['events'=>[$event],'imported_at'=>gmdate('c')]));
  $user=['id'=>1,'role'=>'teacher'];$view=class_group_view($pdo,$user,'1HLS',null,$now);
  integration_check($view['automatic'] && $view['group_ids']===[$a],'current lesson automatically filters group');
  $overridden=class_group_view($pdo,$user,'1HLS',(string)$b,$now);
  integration_check(!$overridden['automatic'] && $overridden['group_ids']===[$b] && $overridden['filter_query']===(string)$b,'explicit group switch overrides timetable within own class');
  $all=class_group_view($pdo,$user,'1HLS','all',$now);
  integration_check($all['group_ids']===[] && $all['filter_query']==='all' && !$all['automatic'],'whole-class choice remains explicit for redirects and SSE');
  integration_check(class_group_view($pdo,$user,'1HLS','auto',$now)['group_ids']===[$a],'automatic mode returns to timetable group');
  try{class_group_view($pdo,$user,'1HLS',(string)$other,$now);throw new LogicException('Foreign automatic override accepted');}catch(InvalidArgumentException $e){integration_check(true,'manual override cannot select group from another class');}
  $both=$event;$both['classes']=['1HLSa','1HLSb'];
  timetable_save($pdo,1,timetable_defaults(['events'=>[$both]]));
  integration_check(class_group_view($pdo,$user,'1HLS',null,$now)['group_ids']===[],'both lesson groups show full class');
  timetable_save($pdo,1,timetable_defaults(['enabled'=>false,'events'=>[$event]]));
  $manual=class_group_view($pdo,$user,'1HLS',null,$now);
  integration_check(!$manual['automatic'] && $manual['group_ids']===[],'disabled timetable starts with whole class');
  integration_check(class_group_view($pdo,$user,'1HLS',(string)$b,$now)['group_ids']===[$b],'manual filter works without timetable');
  try{class_group_view($pdo,$user,'1HLS',(string)$other,$now);throw new LogicException('Foreign filter accepted');}catch(InvalidArgumentException $e){integration_check(true,'foreign manual filter rejected');}
  $future=$event;$future['id']=hash('sha256','next-group-b');$future['start']=$now+1800;$future['end']=$now+3600;$future['classes']=['1HLSb'];
  timetable_save($pdo,1,timetable_defaults(['events'=>[$event,$future]]));
  $st=$pdo->prepare('INSERT INTO teacher_class_calendar_selection(teacher_id,class_name,event_id) VALUES(1,?,?)');$st->execute(['1HLS',$future['id']]);
  integration_check(class_group_view($pdo,$user,'1HLS',null,$now)['group_ids']===[$b],'explicitly adopted upcoming lesson keeps its group');
  $pdo->exec('DELETE FROM teacher_class_calendar_selection WHERE teacher_id=1');
  integration_check(class_group_view($pdo,$user,'1HLS',null,$now)['group_ids']===[$a],'manual class selection can clear the adopted lesson');
  $before=class_group_view($pdo,$user,'1HLS',null,$now)['token'];
  integration_check(class_group_view($pdo,$user,'1HLS',null,$future['start'])['token']!==$before,'lesson boundary invalidates old form scope');
  $st=$pdo->prepare('INSERT INTO teacher_class_calendar_selection(teacher_id,class_name,event_id) VALUES(1,?,?)');$st->execute(['1HLS',$event['id']]);
  $pdo->exec("INSERT INTO app_settings(k,v) VALUES('webuntis_mappings_v1','{\"classes\":{\"1HLS\":\"1HLS\"}}')");
  $pdo->exec("UPDATE users SET class_name='2HLS-new' WHERE class_name='1HLS'; UPDATE teacher_responsibility SET class_name='2HLS-new' WHERE class_name='1HLS'");
  class_groups_rename_class($pdo,'1HLS','2HLS-new');
  integration_check(class_groups_student_group($pdo,2)===$a && class_groups_list($pdo,'2HLS-new')[0]['class_name']==='2HLS-new','class rename retains student-group relation');
  integration_check(timetable_mappings($pdo)['classes']['1HLS']==='2HLS-new','class rename updates central class targets');
  $st=$pdo->query('SELECT class_name FROM teacher_class_calendar_selection WHERE teacher_id=1');integration_check($st->fetchColumn()==='2HLS-new','class rename retains adopted lesson linkage');
  $pdo->exec("UPDATE users SET class_name='2HLS' WHERE id=2");
  integration_check(class_groups_student_group($pdo,2)===0,'stale membership after class move is never used');
  $pdo->exec('DELETE FROM class_groups WHERE id='.(int)$b);
  integration_check((int)$pdo->query('SELECT COUNT(*) FROM student_class_groups WHERE student_id=3')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE id=3')->fetchColumn()===1,'deleting group clears membership but never deletes students');
  $pdo->exec('DELETE FROM teacher_responsibility WHERE teacher_id=1');
  integration_check((int)$pdo->query('SELECT COUNT(*) FROM teacher_class_calendar_selection')->fetchColumn()===0,'daily reset automatically clears adopted lesson selection');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
echo "ALL CLASS GROUP INTEGRATION TESTS PASSED\n";
