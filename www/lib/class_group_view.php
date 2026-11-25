<?php
declare(strict_types=1);
require_once __DIR__.'/timetable.php';

function class_group_view(PDO $pdo,array $user,string $class,?string $manual=null,?int $now=null): array {
  $now=$now??time();$groups=class_groups_list($pdo,$class);$ids=[];$automatic=false;$note='';$eventIds=[];$next=null;$usesTimetable=false;
  if(($user['role']??'')==='teacher'){
    $calendar=timetable_load($pdo,(int)$user['id']);
    $usesTimetable=$calendar['enabled'] && timetable_has_import($calendar);
    if($calendar['enabled']){
      $events=timetable_current($calendar,$now);
      $st=$pdo->prepare('SELECT event_id FROM teacher_class_calendar_selection WHERE teacher_id=? AND class_name=?');$st->execute([(int)$user['id'],$class]);$selected=$st->fetchColumn();
      if($selected){
        $chosen=array_values(array_filter($calendar['events'],fn($e)=>$e['id']===$selected && $e['end']>$now));
        if($chosen)$events=$chosen;
      }
      $scope=timetable_class_scope($pdo,$calendar,$events);
      if($scope['class']===$class){$ids=$scope['group_ids'];$automatic=true;$eventIds=array_column($events,'id');}
      elseif($events)$note='Kein eindeutiger Stundenplanfilter für diese Klasse. Ganze Klasse oder manuell gewählte Gruppe wird angezeigt.';
      foreach($calendar['events'] as $event)foreach(['start','end'] as $boundary)if($event[$boundary]>$now)$next=$next===null?$event[$boundary]:min($next,$event[$boundary]);
    }
  }
  $manualId=0;
  $manualOverride=$manual!==null && $manual!=='' && $manual!=='auto';
  if($manualOverride){
    if($manual==='all')$ids=[];
    else{
      $manualId=filter_var($manual,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
      if(!$manualId || !in_array($manualId,array_map('intval',array_column($groups,'id')),true))throw new InvalidArgumentException('Diese Gruppe gehört nicht zur gewählten Klasse.');
      $ids=[$manualId];
    }
    // An explicit view choice lasts for this URL, not as a change to the calendar.
    $automatic=false;$eventIds=[];$next=null;$note='';
  }
  $names=[];foreach($groups as $group)if(in_array((int)$group['id'],$ids,true))$names[]=$group['name'];
  $label=$ids?'Gruppe '.implode(' + ',$names):'Ganze Klasse';
  sort($eventIds);
  return ['group_ids'=>$ids,'automatic'=>$automatic,'label'=>$label,'groups'=>$groups,'manual_id'=>$manualId,'note'=>$note,
    'manual_override'=>$manualOverride,'uses_timetable'=>$usesTimetable,'filter_query'=>$manualOverride?($manualId?(string)$manualId:'all'):'',
    'token'=>hash('sha256',json_encode([$class,$ids,$automatic,$eventIds,$manualOverride],JSON_THROW_ON_ERROR)),
    'next_change'=>$next,'now'=>$now];
}
