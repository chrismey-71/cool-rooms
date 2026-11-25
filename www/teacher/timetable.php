<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/csrf.php';
require_once __DIR__.'/../lib/timetable.php';
$u=require_teacher();
if($u['role']!=='teacher'){http_response_code(403);exit('Der persönliche Stundenplan ist nur für Lehrkräfte verfügbar. Zentrale Zuordnungen befinden sich im Adminbereich.');}
$pdo=db(); $teacher=(int)$u['id'];
$data=timetable_load($pdo,$teacher); $error='';
$needsReimport=false;foreach($data['events'] as $event)if(!isset($event['description_format'])){$needsReimport=true;break;}
if($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check(); $action=(string)($_POST['action'] ?? '');
  try {
    if($action==='delete') {
      $st=$pdo->prepare('DELETE FROM teacher_timetables WHERE teacher_id=?');$st->execute([$teacher]);
      flash_set('success','Persönlicher Import und Zugangsdaten gelöscht. Zentrale Zuordnungen bleiben erhalten.');
    } elseif($action==='settings') {
      $data['enabled']=isset($_POST['enabled']);
      $data['auto_refresh']=isset($_POST['auto_refresh']);
      timetable_save($pdo,$teacher,$data);flash_set('success','Persönliche Einstellung gespeichert.');
    } elseif($action==='import') {
      $candidate=$data;
      if(!empty($_FILES['ical']['name'])) {
        $file=$_FILES['ical'];
        if($file['error']!==UPLOAD_ERR_OK || $file['size']>2097152 || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Datei nicht lesbar oder größer als 2 MB.');
        $ics=file_get_contents($file['tmp_name']);
      } else {
        foreach(['feed_url','feed_user','feed_password'] as $key) {
          $value=(string)($_POST[$key] ?? '');
          if($key!=='feed_password')$value=trim($value);
          if($key==='feed_url' && $value!=='' && $value!==$candidate['feed_url']) {$candidate['feed_user']='';$candidate['feed_password']='';}
          if($value!=='')$candidate[$key]=$value;
        }
        if($candidate['feed_url']==='')throw new RuntimeException('Bitte eine iCal-Datei oder WebUntis-iCal-Adresse angeben.');
        $ics=timetable_fetch($candidate['feed_url'],$candidate['feed_user'],$candidate['feed_password']);
      }
      $candidate=timetable_import_data($candidate,(string)$ics,!empty($_FILES['ical']['name']));
      timetable_save($pdo,$teacher,$candidate);
      flash_set('success',count($candidate['events']).' Termine importiert. Die zentralen Zuordnungen werden automatisch verwendet.');
    } else throw new RuntimeException('Unbekannte Aktion.');
    redirect(url('/teacher/timetable.php'));
  }catch(Throwable $e){$error=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Änderung nicht möglich. Bisheriger Import bleibt erhalten.';}
}
$zone=new DateTimeZone('Europe/Vienna');$today=(new DateTimeImmutable('now',$zone))->format('Y-m-d');
$hasImport=timetable_has_import($data);
$date=(string)($_GET['date'] ?? $today);
$selected=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
if(!$selected || $selected->format('Y-m-d')!==$date){$date=$today;$selected=new DateTimeImmutable($today,$zone);}
$dayEvents=array_values(array_filter($data['events'],fn($e)=>(new DateTimeImmutable('@'.$e['start']))->setTimezone($zone)->format('Y-m-d')===$date));
require __DIR__.'/../partials/header.php';
?>
<div class="card timetable-heading">
  <div><h2>Mein Stundenplan</h2><p class="small"><?= count($data['events']) ?> Termine · Letzter Import: <?= h($data['imported_at']?(new DateTimeImmutable($data['imported_at']))->setTimezone($zone)->format('d.m.Y H:i'):'noch keiner') ?></p></div>
  <a class="btn" href="<?= h(url('/teacher/home.php')) ?>">Zum Dashboard und zur schnellen Übernahme</a>
</div>
<?php if($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
<?php if(!$hasImport) require __DIR__.'/../partials/timetable_help.php'; ?>
<?php if($needsReimport): ?><p class="small">Älterer Import: Eindeutig erkannte Lehrkraftkürzel werden für die Anzeige bereits korrigiert. Für eine vollständige Auswertung im neuen Format kannst du den Kalender erneut importieren.</p><?php endif; ?>
<?php if($hasImport): ?><section class="card"><h3>Tagesübersicht</h3>
  <div class="timetable-date-nav">
    <a class="btn" href="?date=<?= h($selected->modify('-1 day')->format('Y-m-d')) ?>">Vorheriger Tag</a>
    <form method="get"><label>Tag<input type="date" name="date" value="<?= h($date) ?>"></label><button>Anzeigen</button></form>
    <a class="btn" href="<?= h(url('/teacher/timetable.php')) ?>">Heute</a>
    <a class="btn" href="?date=<?= h($selected->modify('+1 day')->format('Y-m-d')) ?>">Nächster Tag</a>
  </div>
  <?php if(!$dayEvents): ?><p>Keine importierten Termine für diesen Tag.</p><?php endif; ?>
  <?php foreach($dayEvents as $event): $start=(new DateTimeImmutable('@'.$event['start']))->setTimezone($zone);$end=(new DateTimeImmutable('@'.$event['end']))->setTimezone($zone);$targets=timetable_targets($pdo,$data,[$event]); ?>
  <article class="timetable-slot">
    <h4><?= h($start->format('H:i').'–'.$end->format('H:i').' · '.$event['subject']) ?> <?= $event['start']<=time() && time()<$event['end']?' · Jetzt':'' ?></h4>
    <p>WebUntis: Raum <?= h($event['room']) ?> · <?= h(implode(', ',$event['classes'])) ?></p>
    <p class="small">COOL Rooms: <?= $targets['room']?'Raum zugeordnet':'Raumzuordnung fehlt' ?> · <?= h($targets['class'] ?: 'Klassenzuordnung fehlt') ?>. Änderungen direkt im Dashboard übernehmen.</p>
  </article>
  <?php endforeach; ?>
</section><?php endif; ?>
<details class="card" id="settings" <?= !$hasImport?'open':'' ?>>
<summary><strong>Import und persönliche Einstellungen</strong> · Vorauswahl <?= $data['enabled']?'aktiv':'inaktiv' ?></summary>
<section><h3>Stundenplan verwenden</h3>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
<label class="timetable-checkbox"><input type="checkbox" name="enabled" <?= $data['enabled']?'checked':'' ?>> Stundenplan für Raum- und Klassenvorauswahl verwenden</label>
<label class="timetable-checkbox"><input type="checkbox" name="auto_refresh" <?= $data['auto_refresh']?'checked':'' ?>> Gespeicherte WebUntis-Adresse automatisch aktualisieren</label>
<p class="small">Nur wirksam, wenn die Administration den Cron-Job eingerichtet hat und eine iCal-Adresse gespeichert ist. Ein Datei-Upload schaltet den automatischen Abruf aus; zum Wechsel zurück die Einstellung wieder aktivieren. Die Aktualisierung ändert nur Termine, nicht deinen Raum oder deine verantwortliche Klasse.</p>
<p class="small">Die Administration ordnet Kürzel einmal zentral zu. Du brauchst keine Räume oder Klassen selbst zuzuordnen. Die Tasten im Dashboard übernehmen nur deine Auswahl, keine Schüler:innen-Buchungen. Bloßes Öffnen einer Seite ändert nichts.</p><button>Einstellung speichern</button></form></section>
<section><h3>Kalender importieren oder aktualisieren</h3>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="import">
<label>Persönliche HTTPS-iCal-Adresse<input type="password" name="feed_url" autocomplete="off" placeholder="<?= $data['feed_url']!==''?'Adresse gespeichert – leer lassen zum Beibehalten':'https://…webuntis.com/…' ?>"></label>
<details><summary>Optional: HTTP-Zugangsdaten</summary><p class="small">Nur falls der iCal-Endpunkt HTTP-Basic-Zugangsdaten verlangt. Nicht automatisch dein normales WebUntis-Loginkennwort verwenden.</p>
<label>HTTP-Benutzername<input name="feed_user" autocomplete="off"></label><label>HTTP-Kennwort<input type="password" name="feed_password" autocomplete="new-password"></label></details>
<details><summary>Alternativ: iCal-Datei hochladen</summary><label>Datei (bis 2 MB)<input type="file" name="ical" accept=".ics,text/calendar"></label><p class="small">Eine ausgewählte Datei hat Vorrang vor der Adresse.</p></details>
<p><button>Kalender importieren / aktualisieren</button></p></form>
<p class="small">Manuelle Aktualisierung oder optionaler täglicher Abruf per Cron-Job bei gespeicherter WebUntis-Adresse. Ein Import ersetzt nur deinen gespeicherten Kalender. Zugangsdaten und Termine werden verschlüsselt gespeichert; gespeicherte Kennwörter werden nicht wieder angezeigt. Nur Einzeltermine, keine Wiederholungsregeln.</p></section>
<details><summary>Persönlichen Import entfernen</summary><form method="post" onsubmit="return confirm('Persönlichen Kalender und gespeicherte Import-Zugangsdaten löschen?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><p class="small">Aktuelle Raum-/Klassenauswahl und zentrale Kürzel bleiben bestehen.</p><button class="danger">Import und Zugangsdaten löschen</button></form></details>
</details>
<?php if($hasImport) require __DIR__.'/../partials/timetable_help.php'; ?>
<?php require __DIR__.'/../partials/footer.php'; ?>
