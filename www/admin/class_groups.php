<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/csrf.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/class_groups.php';
$u=require_admin();$pdo=db();class_groups_schema($pdo);
$class=trim((string)($_GET['class']??''));$error='';
if(!class_groups_class_exists($pdo,$class)){flash_set('error','Bitte zuerst eine vorhandene Klasse wählen.');redirect(url('/admin/classes.php'));}
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();$typeLength=ensure_events_schema($pdo);
  try{
    $pdo->beginTransaction();$action=(string)($_POST['action']??'');
    if($action==='save_group'){
      class_groups_save($pdo,$class,(int)($_POST['id']??0),(string)($_POST['name']??''),(string)($_POST['webuntis_code']??''));
    }elseif($action==='delete_group'){
      $st=$pdo->prepare('DELETE FROM class_groups WHERE id=? AND class_name=?');$st->execute([(int)($_POST['id']??0),$class]);
    }elseif($action==='members'){
      $members=$_POST['groups']??[];if(!is_array($members))throw new InvalidArgumentException('Ungültige Zuordnung.');
      foreach($members as $student=>$group){
        $st=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='student' AND class_name=? FOR UPDATE");$st->execute([(int)$student,$class]);
        if(!$st->fetchColumn())throw new InvalidArgumentException('Person gehört nicht zur gewählten Klasse.');
        class_groups_assign($pdo,(int)$student,(int)$group);
      }
    }else throw new InvalidArgumentException('Unbekannte Aktion.');
    emit_event('class_groups_changed',['actor_id'=>(int)$u['id'],'actor_role'=>'admin','class_name'=>$class,'action'=>$action],$typeLength);
    $pdo->commit();flash_set('success','Gruppen und Zuordnungen gespeichert.');redirect(url('/admin/class_groups.php?class='.urlencode($class)));
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof InvalidArgumentException?$e->getMessage():'Änderung nicht möglich. Gruppenname und WebUntis-Kürzel müssen eindeutig sein.';}
}
$groups=class_groups_list($pdo,$class);
$st=$pdo->prepare("SELECT s.id,s.first_name,s.last_name,g.id AS group_id FROM users s LEFT JOIN student_class_groups m ON m.student_id=s.id LEFT JOIN class_groups g ON g.id=m.group_id AND g.class_name=s.class_name WHERE s.role='student' AND s.class_name=? ORDER BY s.last_name,s.first_name,s.id");$st->execute([$class]);$students=$st->fetchAll();
require __DIR__.'/../partials/header.php';
?>
<div class="card"><div class="timetable-heading"><h2>Gruppen: <?= h($class) ?></h2><a href="<?= h(url('/admin/classes.php')) ?>">Zur Klassenverwaltung</a></div>
<p>Eine Gruppe ist eine Teilklasse, z. B. <strong>a</strong> mit dem WebUntis-Kürzel <strong><?= h($class) ?>a</strong>. Namen und Kürzel sind frei wählbar. Jede Person gehört höchstens einer Gruppe ihrer Klasse an.</p>
<p class="small">Ein einzelnes Gruppenkürzel im Stundenplan zeigt nur diese Gruppe. Sind bei mehreren angelegten Gruppen alle im Termin enthalten oder ist die ganze Klasse zugeordnet, wird die gesamte Klasse angezeigt – auch Personen ohne Gruppe. Die Kürzel hier gelten zentral und haben Vorrang vor allgemeinen WebUntis-Klassenzuordnungen. Gruppen ändern keine Raum- oder Anwesenheitsbuchungen.</p>
<?php if($error): ?><p class="flash error"><?= h($error) ?></p><?php endif; ?>
<section class="card"><h3>Gruppen verwalten</h3>
<?php foreach([...$groups,['id'=>0,'name'=>'','webuntis_code'=>'']] as $group): ?>
<form method="post" class="class-group-editor"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$group['id'] ?>">
<label>Gruppe<input name="name" maxlength="80" required value="<?= h($group['name']) ?>" placeholder="z. B. a"></label>
<label>WebUntis-Kürzel (optional)<input name="webuntis_code" maxlength="120" value="<?= h($group['webuntis_code']??'') ?>" placeholder="<?= h($class) ?>a"></label>
<button name="action" value="save_group"><?= $group['id']?'Speichern':'Gruppe anlegen' ?></button>
<?php if($group['id']): ?><button class="danger" name="action" value="delete_group" formnovalidate onclick="return confirm('Nur diese Gruppe löschen? Die Schüler:innen bleiben erhalten und sind danach ohne Gruppe.');">Gruppe löschen</button><?php endif; ?>
</form><?php endforeach; ?></section>
<section class="card"><h3>Schüler:innen den Gruppen zuordnen</h3>
<?php if(!$groups): ?><p>Lege zuerst die gewünschten Gruppen an.</p><?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="members">
<table class="table"><thead><tr><th>Nr.</th><th>Name</th><th>Gruppe</th></tr></thead><tbody>
<?php foreach($students as $i=>$student): ?><tr id="student-<?= (int)$student['id'] ?>"><td><?= $i+1 ?></td><td><?= h($student['last_name'].', '.$student['first_name']) ?></td><td><select name="groups[<?= (int)$student['id'] ?>]" aria-label="Gruppe für <?= h($student['first_name'].' '.$student['last_name']) ?>"><option value="0">Ohne Gruppe</option><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$student['group_id']===(int)$g['id']?'selected':'' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
</tbody></table><button>Gruppenzuordnungen speichern</button></form><?php endif; ?></section></div>
<?php require __DIR__.'/../partials/footer.php'; ?>
