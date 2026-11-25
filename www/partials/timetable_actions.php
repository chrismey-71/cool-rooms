<?php
$targets=timetable_targets($pdo,$data,[$event]);
$targetRoom='';
if($targets['room']){$st=$pdo->prepare('SELECT name FROM rooms WHERE id=?');$st->execute([$targets['room']]);$targetRoom=(string)$st->fetchColumn();}
$zone=new DateTimeZone('Europe/Vienna');
$start=(new DateTimeImmutable('@'.$event['start']))->setTimezone($zone);
$end=(new DateTimeImmutable('@'.$event['end']))->setTimezone($zone);
$isCurrent=$event['start']<=time() && time()<$event['end'];
$sameDay=$start->format('Y-m-d')===(new DateTimeImmutable('now',$zone))->format('Y-m-d');
$roomLabel=$targetRoom ?: ($event['room']!==''?$event['room'].' (offen)':'Raum offen');
$classLabel=$targets['class'] ?: (!empty($event['classes'])?implode(', ',$event['classes']).' (offen)':'Klasse offen');
$groupScope=timetable_class_scope($pdo,$data,[$event]);
if($groupScope['group_ids']){
  $groupNames=[];foreach(class_groups_list($pdo,$groupScope['class']) as $g)if(in_array((int)$g['id'],$groupScope['group_ids'],true))$groupNames[]=$g['name'];
  $classLabel.=' · '.implode(' + ',$groupNames);
}
?>
<article class="timetable-mini <?= $isCurrent?'timetable-mini--current':'' ?>">
  <div class="timetable-mini__time"><strong><?= h($start->format('H:i').'–'.$end->format('H:i')) ?></strong><span><?= $isCurrent?'Jetzt':($event['end']<=time()?'Vorbei':($sameDay?'Danach':'')) ?></span></div>
  <h4 title="<?= h($event['subject']) ?>"><?= h($event['subject'] ?: 'Unterricht') ?></h4>
  <p class="timetable-mini__targets" title="<?= h('Raum: '.$roomLabel.' · Klasse: '.$classLabel) ?>"><?= h($roomLabel.' · '.$classLabel) ?></p>
<?php if(!$data['enabled']): ?>
  <a class="timetable-mini__note" href="<?= h(url('/teacher/timetable.php#settings')) ?>">Vorauswahl deaktiviert</a>
<?php else: ?>
  <?php if($targets['room'] || $targets['class']!==''): ?>
  <form method="post" action="<?= h(url('/teacher/timetable_apply.php')) ?>" class="timetable-actions">
    <span class="timetable-actions__label">Zuordnen:</span>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= h($event['id']) ?>">
    <?php if($targets['room']): ?><button type="submit" name="mode" value="room" aria-label="Raum übernehmen" title="Raum übernehmen">Raum</button><?php endif; ?>
    <?php if($targets['class']!==''): ?><button type="submit" name="mode" value="class" aria-label="Klasse übernehmen" title="Klasse übernehmen">Klasse</button><?php endif; ?>
    <?php if($targets['room'] && $targets['class']!==''): ?><button type="submit" name="mode" value="both" aria-label="Raum und Klasse übernehmen" title="Raum und Klasse übernehmen">Beides</button><?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if(!$targets['room'] || $targets['class']===''): ?><span class="timetable-mini__note" title="Fehlende oder abweichende Kürzel bitte der Administration melden.">Zuordnung fehlt</span><?php endif; ?>
<?php endif; ?>
</article>
