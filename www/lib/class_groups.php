<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';

function class_groups_schema(PDO $pdo): void {
  static $ready=[];$key=spl_object_id($pdo);if(isset($ready[$key]))return;
  $pdo->exec('CREATE TABLE IF NOT EXISTS class_groups (
    id INT AUTO_INCREMENT PRIMARY KEY, class_name VARCHAR(80) NOT NULL,
    name VARCHAR(80) NOT NULL, webuntis_code VARCHAR(120) NULL,
    UNIQUE KEY uq_class_group(class_name,name), UNIQUE KEY uq_group_untis(webuntis_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS student_class_groups (
    student_id INT PRIMARY KEY, group_id INT NOT NULL,
    FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(group_id) REFERENCES class_groups(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_class_calendar_selection (
    teacher_id INT PRIMARY KEY, class_name VARCHAR(80) NOT NULL, event_id CHAR(64) NOT NULL,
    FOREIGN KEY(teacher_id) REFERENCES teacher_responsibility(teacher_id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $ready[$key]=true;
}

function class_groups_list(PDO $pdo, ?string $class=null): array {
  $st=$pdo->prepare('SELECT id,class_name,name,webuntis_code FROM class_groups'.($class!==null?' WHERE class_name=?':'').' ORDER BY class_name,name');
  $st->execute($class!==null?[$class]:[]);return $st->fetchAll(PDO::FETCH_ASSOC);
}

function class_groups_class_exists(PDO $pdo,string $class): bool {
  $st=$pdo->prepare("SELECT 1 FROM users WHERE role='student' AND class_name=? UNION SELECT 1 FROM class_settings WHERE class_name=? UNION SELECT 1 FROM class_groups WHERE class_name=? LIMIT 1");
  $st->execute([$class,$class,$class]);return (bool)$st->fetchColumn();
}

function class_groups_save(PDO $pdo,string $class,int $id,string $name,string $code): int {
  $name=trim($name);$code=trim($code);
  if(!class_groups_class_exists($pdo,$class) || $name==='' || mb_strlen($name)>80 || mb_strlen($code)>120)throw new InvalidArgumentException('Vorhandene Klasse, Gruppenname (bis 80 Zeichen) und optionales WebUntis-Kürzel (bis 120 Zeichen) angeben.');
  $st=$pdo->prepare('SELECT id FROM class_groups WHERE (class_name=? AND name=?) OR (webuntis_code=? AND ?<>\'\')');
  $st->execute([$class,$name,$code,$code]);
  foreach($st->fetchAll(PDO::FETCH_COLUMN) as $existing)if((int)$existing!==$id)throw new InvalidArgumentException('Gruppenname in dieser Klasse oder WebUntis-Kürzel bereits vergeben.');
  if($id>0){
    $st=$pdo->prepare('SELECT id FROM class_groups WHERE id=? AND class_name=?');$st->execute([$id,$class]);
    if(!$st->fetchColumn())throw new InvalidArgumentException('Gruppe gehört nicht zu dieser Klasse.');
    $st=$pdo->prepare('UPDATE class_groups SET name=?,webuntis_code=? WHERE id=? AND class_name=?');$st->execute([$name,$code?:null,$id,$class]);
  }else{
    $st=$pdo->prepare('INSERT INTO class_groups(class_name,name,webuntis_code) VALUES(?,?,?)');$st->execute([$class,$name,$code?:null]);$id=(int)$pdo->lastInsertId();
  }
  return $id;
}

/** Validate the student's current class even when an old membership survived a CSV move. */
function class_groups_assign(PDO $pdo,int $student,int $group): void {
  $st=$pdo->prepare("SELECT class_name FROM users WHERE id=? AND role='student' FOR UPDATE");$st->execute([$student]);$class=$st->fetchColumn();
  if($class===false)throw new InvalidArgumentException('Schüler:in nicht gefunden.');
  if($group>0){
    $st=$pdo->prepare('SELECT id FROM class_groups WHERE id=? AND class_name=? FOR UPDATE');$st->execute([$group,$class]);
    if(!$st->fetchColumn())throw new InvalidArgumentException('Die Gruppe gehört nicht zur Klasse dieser Person.');
    $st=$pdo->prepare('INSERT INTO student_class_groups(student_id,group_id) VALUES(?,?) ON DUPLICATE KEY UPDATE group_id=VALUES(group_id)');$st->execute([$student,$group]);
  }else{$st=$pdo->prepare('DELETE FROM student_class_groups WHERE student_id=?');$st->execute([$student]);}
}

function class_groups_student_group(PDO $pdo,int $student): int {
  $st=$pdo->prepare("SELECT g.id FROM student_class_groups m JOIN users s ON s.id=m.student_id JOIN class_groups g ON g.id=m.group_id AND g.class_name=s.class_name WHERE s.id=? AND s.role='student'");
  $st->execute([$student]);return (int)($st->fetchColumn()?:0);
}

/** A parameterized condition shared by the list, actions and SSE subscription. */
function class_groups_condition(array $ids,string $alias='s'): array {
  if(!$ids)return ['',[]];
  $ids=array_values(array_unique(array_map('intval',$ids)));
  return [" AND EXISTS(SELECT 1 FROM student_class_groups gm JOIN class_groups gg ON gg.id=gm.group_id WHERE gm.student_id=$alias.id AND gg.class_name=$alias.class_name AND gg.id IN (".implode(',',array_fill(0,count($ids),'?')).'))',$ids];
}

function class_groups_rename_class(PDO $pdo,string $old,string $new): void {
  $st=$pdo->prepare('UPDATE class_groups SET class_name=? WHERE class_name=?');$st->execute([$new,$old]);
  $st=$pdo->prepare('UPDATE teacher_class_calendar_selection SET class_name=? WHERE class_name=?');$st->execute([$new,$old]);
  $st=$pdo->prepare('SELECT v FROM app_settings WHERE k=? FOR UPDATE');$st->execute(['webuntis_mappings_v1']);$raw=$st->fetchColumn();
  if($raw!==false){
    $mapping=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
    foreach(($mapping['classes']??[]) as $source=>$target)if($target===$old)$mapping['classes'][$source]=$new;
    $st=$pdo->prepare('UPDATE app_settings SET v=? WHERE k=?');$st->execute([json_encode($mapping,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'webuntis_mappings_v1']);
  }
}
