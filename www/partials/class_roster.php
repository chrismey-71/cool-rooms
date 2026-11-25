<?php
$presentCount=count(array_filter($students,fn($s)=>(int)$s['is_active']===1));
$unbookedCount=count(array_filter($students,fn($s)=>(int)$s['is_active']===1 && !$s['room_name']));
$streamUrl=url('/stream.php?scope=class&class='.urlencode($class)).($view['filter_query']!==''?'&group='.urlencode($view['filter_query']):'');
$nextChange=$view['next_change']!==null?max(5000,min(86400000,($view['next_change']-$view['now']+1)*1000)):0;
?>
<section class="card class-roster" data-class-roster data-class="<?= h($class) ?>" data-stream-url="<?= h($streamUrl) ?>" data-next-change-ms="<?= $nextChange ?>" data-return-key="<?= h('class-roster-'.(int)$u['id'].'-'.$view['token']) ?>">
  <header class="class-roster__heading">
    <div>
      <h2><?= h($class) ?> <span class="class-roster__live">Live</span></h2>
      <p class="class-roster__scope"><?= h($view['label']) ?> <span>· <?= $view['automatic']?'aus dem Stundenplan':($view['manual_override']?'manuell gewählt':'manuelle Ansicht') ?></span></p>
    </div>
    <details class="class-roster__actions">
      <summary>Aktionen</summary>
      <div class="class-roster__action-panel class-group-actions">
        <h3><?= h($class.' · '.$view['label']) ?></h3>
        <p class="small">Betrifft die gesamte oben genannte Klasse oder Gruppe, auch bei aktiver Namenssuche. Nicht anwesende Personen werden beim Buchen übersprungen.</p>
        <form method="post" onsubmit="return confirm('Alle anwesenden Schüler:innen der oben genannten Klasse oder Gruppe in den Klassenraum buchen – unabhängig von der Namenssuche?')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="group_scope" value="<?= h($view['token']) ?>">
          <button name="action" value="book_home_room" <?= $classHomeRoom?'':'disabled' ?>><?= $view['group_ids']?'Gruppe in den Klassenraum buchen':'Alle in den Klassenraum buchen' ?></button>
        </form>
        <p class="small"><?= $classHomeRoom?'Klassenraum: '.h($classHomeRoom['name']):'Kein aktiver Klassenraum hinterlegt.' ?></p>
        <form method="post" onsubmit="return confirm('Raumzuordnungen der gesamten oben genannten Klasse oder Gruppe löschen – unabhängig von der Namenssuche?')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="group_scope" value="<?= h($view['token']) ?>">
          <button class="danger" name="action" value="reset_assignments">Raumzuordnungen löschen</button>
        </form>
      </div>
    </details>
  </header>

  <?php if($view['note']): ?><p class="flash info"><?= h($view['note']) ?></p><?php endif; ?>
  <?php if($view['groups']): ?>
    <nav class="class-roster__groups" aria-label="Gruppe wechseln">
      <?php if($view['uses_timetable']): ?><a href="<?= h(url('/teacher/view_class.php')) ?>" <?= !$view['manual_override']?'aria-current="page"':'' ?> title="Automatische Gruppenauswahl aus dem Stundenplan">Stundenplan</a><?php endif; ?>
      <a href="<?= h(url('/teacher/view_class.php?group=all')) ?>" <?= !$view['automatic'] && !$view['manual_id'] && ($view['manual_override'] || !$view['uses_timetable'])?'aria-current="page"':'' ?>>Ganze Klasse</a>
      <?php foreach($view['groups'] as $g): ?><a href="<?= h(url('/teacher/view_class.php?group='.(int)$g['id'])) ?>" <?= $view['manual_override'] && $view['manual_id']===(int)$g['id']?'aria-current="page"':'' ?> aria-label="<?= h('Gruppe '.$g['name'].' anzeigen') ?>" title="<?= h($class.' · Gruppe '.$g['name']) ?>"><?= h($g['name']) ?></a><?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="class-roster__totals" aria-label="Klassenübersicht" aria-live="polite" aria-atomic="true">
    <span><strong><?= count($students) ?></strong> gesamt</span>
    <span class="class-roster__present"><strong data-present-count><?= $presentCount ?></strong> anwesend</span>
    <span class="class-roster__unbooked"><strong data-unbooked-count><?= $unbookedCount ?></strong> ohne Raum</span>
  </div>
  <div class="class-roster__search" data-roster-search hidden>
    <label class="sr-only" for="roster-search">Schüler:innen nach Namen suchen</label>
    <input id="roster-search" type="search" placeholder="Namen suchen …" autocomplete="off" aria-controls="class-table" data-roster-query>
    <span class="small" data-search-count role="status"></span>
  </div>
  <p class="class-roster__hint" id="attendance-hint">Anwesenheit antippen zum Ändern.</p>

  <table class="class-roster__table" id="class-table" role="table" aria-label="Schüler:innen und Räume">
    <thead role="rowgroup"><tr role="row"><th scope="col">Nr.</th><th scope="col">Name</th><th scope="col">Raum</th><th scope="col">Anwesenheit</th></tr></thead>
    <tbody role="rowgroup">
    <?php foreach($students as $i=>$s): $present=(int)$s['is_active']===1; $name=$s['last_name'].', '.$s['first_name']; ?>
      <tr role="row" data-student-id="<?= (int)$s['id'] ?>" data-present="<?= $present?'1':'0' ?>" data-booked="<?= $s['room_name']?'1':'0' ?>" class="<?= $present?'':'class-roster__row--absent' ?>">
        <td role="cell" class="class-roster__number"><?= $i+1 ?></td>
        <td role="cell" class="class-roster__name"><?= h($name) ?></td>
        <td role="cell" class="room-cell" title="<?= h('Stand: '.($s['updated_at']?:'noch keine Raumbuchung')) ?>">
          <span class="class-roster__room <?= $s['room_name']?'':'class-roster__room--empty' ?>"><?= h($s['room_name']?:'Kein Raum') ?></span>
        </td>
        <td role="cell" class="class-roster__attendance">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="group_scope" value="<?= h($view['token']) ?>">
            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
            <button class="class-roster__status <?= $present?'class-roster__status--present':'class-roster__status--absent' ?>" type="submit" name="action" value="<?= $present?'mark_inactive':'mark_active' ?>" aria-describedby="attendance-hint" aria-label="<?= h($name.($present?': nicht anwesend setzen':': anwesend setzen')) ?>" title="<?= $present?'Nicht anwesend setzen':'Anwesend setzen' ?>">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $present?'m3 8 3 3 7-7':'M4 8h8' ?>"/></svg>
              <span><?= $present?'Anwesend':'Nicht anwesend' ?></span>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if(!$students): ?><p class="small">Keine Schüler:innen in dieser Auswahl.</p><?php endif; ?>
  <p class="class-roster__empty" data-search-empty hidden>Kein passender Name gefunden.</p>
</section>
<script defer src="<?= h(url('/assets/class-roster.js?v='.filemtime(__DIR__.'/../assets/class-roster.js'))) ?>"></script>
