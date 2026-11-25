<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/csrf.php';
require_once __DIR__.'/../lib/timetable.php';
$u=require_admin(); $pdo=db(); timetable_schema($pdo);
$mapping=timetable_mappings($pdo); $error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $candidate=['rooms'=>[],'classes'=>[],'teachers'=>[]];
    foreach($candidate as $kind=>$_) {
      foreach((array)($_POST['source'][$kind] ?? []) as $i=>$source) {
        $source=trim((string)$source);if($source==='')continue;
        if(array_key_exists($source,$candidate[$kind]))throw new RuntimeException('Ein Kürzel darf je Bereich nur einmal vorkommen.');
        $candidate[$kind][$source]=(string)($_POST['target'][$kind][$i] ?? '');
      }
    }
    timetable_save_mappings($pdo,$candidate);
    flash_set('success','Zentrale WebUntis-Zuordnungen gespeichert. Sie gelten sofort für alle Lehrkräfte.');
    redirect(url('/admin/webuntis.php'));
  } catch(Throwable $e){$error=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Zuordnungen konnten nicht gespeichert werden.';}
}
$sources=['rooms'=>[],'classes'=>[],'teachers'=>[]];
foreach(array_keys($sources) as $kind)foreach($mapping[$kind] as $source=>$target)$sources[$kind][(string)$source]=true;
$unreadable=0; $legacyImports=0;
foreach($pdo->query('SELECT teacher_id FROM teacher_timetables')->fetchAll(PDO::FETCH_COLUMN) as $teacher) {
  try {
    $calendar=timetable_load($pdo,(int)$teacher);
    $legacy=false;
    foreach($calendar['events'] as $event){
      if(!isset($event['description_format']))$legacy=true;
      if($event['room']!=='')$sources['rooms'][$event['room']]=true;
      foreach($event['classes'] as $class)$sources['classes'][$class]=true;
      foreach(($event['teachers'] ?? []) as $code)$sources['teachers'][$code]=true;
    }
    if($legacy)$legacyImports++;
  }catch(Throwable $e){$unreadable++;}
}
$options=['rooms'=>[],'classes'=>[],'teachers'=>[]];
$groupAliases=[];foreach(class_groups_list($pdo) as $g)if($g['webuntis_code']!==null){$groupAliases[$g['webuntis_code']]=$g;unset($sources['classes'][$g['webuntis_code']]);}
foreach($pdo->query('SELECT id,name,is_active FROM rooms ORDER BY name')->fetchAll() as $r)$options['rooms'][$r['id']]=$r['name'].(!(int)$r['is_active']?' (inaktiv – keine Übernahme)':'');
foreach($pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>'' ORDER BY class_name")->fetchAll(PDO::FETCH_COLUMN) as $c)$options['classes'][$c]=$c;
// Old saved mappings must not reintroduce recognized teacher codes as class rows.
$misplaced=[];
foreach(array_keys($sources['teachers']) as $code) {
  if(isset($sources['classes'][$code]) && !array_key_exists($code,$options['classes'])) {
    unset($sources['classes'][$code]);$misplaced[]=$code;
  }
}
foreach($pdo->query("SELECT id,first_name,last_name,username FROM users WHERE role='teacher' ORDER BY last_name,first_name")->fetchAll() as $t){
  $name=trim($t['last_name'].' '.$t['first_name']);
  $options['teachers'][$t['id']]=$name!==''?$name:('Lehrkraftkonto #'.$t['id'].' ('.$t['username'].')');
}
$labels=['rooms'=>'Räume zuordnen','classes'=>'Klassen zuordnen','teachers'=>'Lehrer:innen zuordnen'];
require __DIR__.'/../partials/header.php';
?>
<div class="card"><h2>Zentrale WebUntis-Zuordnung</h2>
<p>Einmal einrichten, für alle Lehrkräfte verwenden: beispielsweise <strong>H → Raum H</strong>, <strong>2Aa → 2A</strong> und ein Lehrkraftkürzel zum entsprechenden COOL-Rooms-Konto.</p>
<p class="small">Die Liste ergänzt Kürzel aus bereits importierten persönlichen Stundenplänen. Weitere Kürzel kannst du vorab eintragen. Es werden keine Räume, Klassen oder Konten angelegt. Persönliche Zugangsdaten werden hier nicht angezeigt.</p>
<p class="small">Beim ersten Speichern ersetzen diese zentralen Regeln die bisherigen persönlichen Raum-/Klassenzuordnungen. Bitte alle benötigten Ziele prüfen; leere Ziele bleiben bewusst nicht zugeordnet.</p>
<p class="small">Lehrkraftkürzel dienen der Zuordnung und Plausibilitätsprüfung der Termine, nicht als Login oder iCal-Kennwort. Ein Termin, der ausschließlich anderen zentral zugeordneten Lehrkräften gehört, kann nicht übernommen werden. Jede Lehrkraft importiert weiterhin ihren eigenen Kalender. Bei älteren Importen ohne erfasste Lehrkraftkürzel bitte erneut importieren.</p>
<?php if($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
<?php if($unreadable): ?><p class="flash error"><?= $unreadable ?> Kalender konnten nicht gelesen werden. Bitte Konfigurationsschlüssel prüfen.</p><?php endif; ?>
<?php if($legacyImports): ?><p class="small"><?= $legacyImports ?> ältere Importe werden mit korrigierter Kürzel-Erkennung angezeigt. Eindeutig erkannte Lehrkraftkürzel erscheinen unten bei Lehrer:innen; echte vorhandene Klassennamen bleiben geschützt. Für eine vollständige Auswertung ohne Altformat bitte später neu importieren.</p><?php endif; ?>
<?php if($misplaced): ?><p class="flash info">Früher falsch einsortierte Lehrkraftkürzel wurden aus der Klassenanzeige entfernt: <?= h(implode(', ',$misplaced)) ?>. Bitte unten die Lehrkräfte zuordnen und „Alle Zuordnungen speichern“ klicken. Alte Klassen-Ziele werden dabei nicht als Lehrkraftkonten übernommen.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<?php foreach($labels as $kind=>$label): ksort($sources[$kind],SORT_NATURAL); ?>
<section class="card" id="<?= h($kind) ?>" style="scroll-margin-top:110px"><h3><?= h($label) ?></h3>
<?php if($kind==='rooms'): ?><p class="small">Auch inaktive Räume können zugeordnet werden. Sie bleiben gekennzeichnet und sind erst nach Aktivierung für eine Stundenplanübernahme verfügbar.</p><?php endif; ?>
<?php if($kind==='classes'): ?><p class="small">Gruppenkürzel werden unter <a href="<?= h(url('/admin/classes.php')) ?>">Klassen → Gruppen &amp; Schüler:innen zuordnen</a> gepflegt. Sie gelten vorrangig und filtern auch die Lehrkraftansicht. Die übrigen Zuordnungen hier stehen für die ganze Klasse.</p>
<?php foreach($groupAliases as $code=>$g): ?><p class="small"><strong><?= h($code) ?></strong> → <?= h($g['class_name'].' · Gruppe '.$g['name']) ?> · <a href="<?= h(url('/admin/class_groups.php?class='.urlencode($g['class_name']))) ?>">Gruppe bearbeiten</a></p><?php endforeach; ?>
<?php endif; ?>
<?php if($kind==='teachers'): ?><p class="small">Hier erhält jede Lehrkraft ihre eigene Kürzel-Zuordnung, beispielsweise MEYS zum entsprechenden Lehrkraftkonto. Die derzeit vierstelligen WebUntis-Kürzel sind keine Passwörter. Andere Kürzellängen sind ebenfalls möglich. Reine Administrationskonten werden nicht als Lehrkräfte angeboten; Lehrkraftkonten mit zusätzlichen Adminrechten bleiben auswählbar.</p><?php endif; ?>
<div data-mapping-rows="<?= h($kind) ?>">
<?php foreach(array_merge(array_keys($sources[$kind]),['']) as $source): $target=$mapping[$kind][$source] ?? ''; ?>
<div class="mapping-row">
  <label><?= $kind==='teachers'?'WebUntis-Lehrkraftkürzel (z. B. MEYS)':'WebUntis-Kürzel' ?><input name="source[<?= h($kind) ?>][]" maxlength="120" value="<?= h((string)$source) ?>" placeholder="Kürzel eingeben"></label>
  <label><?= $kind==='teachers'?'Lehrer:in in COOL Rooms (Name)':'COOL Rooms' ?><select name="target[<?= h($kind) ?>][]"><option value="">Nicht zugeordnet</option>
  <?php if($target!=='' && $target!==0 && !array_key_exists($target,$options[$kind])): ?><option selected value="">Bisheriges Ziel nicht mehr verfügbar – neu wählen</option><?php endif; ?>
  <?php foreach($options[$kind] as $id=>$name): ?><option value="<?= h((string)$id) ?>" <?= (string)$target===(string)$id?'selected':'' ?>><?= h($name) ?></option><?php endforeach; ?></select></label>
</div>
<?php endforeach; ?></div>
<button type="button" data-add-mapping="<?= h($kind) ?>">Weiteres Kürzel</button>
<p class="small">Mehrere Kürzel dürfen dasselbe Ziel haben. Leeres Ziel blockiert die automatische Zuordnung dieses Kürzels; zum Entfernen einer Zeile das Kürzel leeren.</p>
</section>
<?php endforeach; ?>
<button type="submit">Alle Zuordnungen speichern</button></form></div>
<p class="small">Import-Erkennung: <?= h(TIMETABLE_IMPORT_REVISION) ?> · Diese Kennung identifiziert die hier geladene Importkorrektur.</p>
<script>
document.querySelectorAll('[data-add-mapping]').forEach(button=>button.addEventListener('click',()=>{
  const rows=document.querySelector('[data-mapping-rows="'+button.dataset.addMapping+'"]');
  const copy=rows.lastElementChild.cloneNode(true);
  copy.querySelector('input').value=''; copy.querySelector('select').value='';
  rows.appendChild(copy); copy.querySelector('input').focus();
}));
</script>
<?php require __DIR__.'/../partials/footer.php'; ?>
