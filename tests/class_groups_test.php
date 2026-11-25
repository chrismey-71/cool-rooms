<?php
declare(strict_types=1);
require __DIR__.'/../www/lib/timetable.php';
function group_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE app_settings(k TEXT,v TEXT); CREATE TABLE users(id INTEGER,role TEXT,class_name TEXT); CREATE TABLE class_groups(id INTEGER,class_name TEXT,name TEXT,webuntis_code TEXT); CREATE TABLE student_class_groups(student_id INTEGER,group_id INTEGER);
INSERT INTO users VALUES(1,'student','1HLS'),(2,'student','1HLS'),(3,'student','1HLS'),(4,'student','2HLS');
INSERT INTO class_groups VALUES(10,'1HLS','a','1HLSa'),(11,'1HLS','b','1HLSb'),(12,'2HLS','a','2HLSa');
INSERT INTO student_class_groups VALUES(1,10),(2,11),(4,10)");
$mapping=['classes'=>['1HLS'=>'1HLS','1HLSa'=>'2HLS'],'teachers'=>['DEMO'=>7]];
$st=$pdo->prepare('INSERT INTO app_settings VALUES(?,?)');$st->execute(['webuntis_mappings_v1',json_encode($mapping)]);
$data=['_teacher_id'=>7,'classes'=>[]];$event=['classes'=>['1HLSa'],'teachers'=>['DEMO']];
group_check(timetable_class_scope($pdo,$data,[$event])===['class'=>'1HLS','group_ids'=>[10]],'one subgroup filters that subgroup and overrides stale whole-class aliases');
$event['classes']=['1HLSb'];group_check(timetable_class_scope($pdo,$data,[$event])['group_ids']===[11],'second subgroup is distinct');
$event['classes']=['1HLSa','1HLSb'];group_check(timetable_class_scope($pdo,$data,[$event])===['class'=>'1HLS','group_ids'=>[]],'all configured subgroups mean the whole class');
$event['classes']=['1HLS'];group_check(timetable_class_scope($pdo,$data,[$event])['group_ids']===[],'whole-class alias remains whole class');
$event['classes']=['1HLS','1HLSa'];group_check(timetable_class_scope($pdo,$data,[$event])['group_ids']===[],'whole-class label overrides a subset in the same lesson');
$event['classes']=['1HLSa','unknown'];group_check(timetable_class_scope($pdo,$data,[$event])['class']==='','unknown subgroup is not guessed by its suffix');
$event['classes']=['1HLSa','2HLSa'];group_check(timetable_class_scope($pdo,$data,[$event])['class']==='','mixed parent classes are not silently merged');
$event['classes']=['1HLSa'];$foreign=$data;$foreign['_teacher_id']=8;group_check(timetable_class_scope($pdo,$foreign,[$event])['class']==='','teacher ownership check also applies to subgroup aliases');
$pdo->exec("INSERT INTO class_groups VALUES(13,'1HLS','c','1HLSc')");$event['classes']=['1HLSa','1HLSb'];
group_check(timetable_class_scope($pdo,$data,[$event])['group_ids']===[10,11],'two of three groups remain a subset');
$pdo->exec('DELETE FROM class_groups WHERE id IN(11,13)');$event['classes']=['1HLSa'];
group_check(timetable_class_scope($pdo,$data,[$event])['group_ids']===[10],'a single configured group never expands to unassigned students');
[$condition,$params]=class_groups_condition([10]);$st=$pdo->prepare("SELECT s.id FROM users s WHERE s.role='student' $condition ORDER BY s.id");$st->execute($params);
group_check($st->fetchAll(PDO::FETCH_COLUMN)===[1],'group roster excludes memberships left over from a different class');
[$condition,$params]=class_groups_condition([]);$st=$pdo->prepare("SELECT s.id FROM users s WHERE s.class_name=? $condition ORDER BY s.id");$st->execute(array_merge(['1HLS'],$params));
group_check($st->fetchAll(PDO::FETCH_COLUMN)===[1,2,3],'whole class includes students with no assigned group');
echo "ALL CLASS GROUP TESTS PASSED\n";
