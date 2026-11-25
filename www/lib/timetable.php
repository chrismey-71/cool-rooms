<?php
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/class_groups.php';
const TIMETABLE_IMPORT_REVISION = '2026-11-25-r3';

function timetable_schema(PDO $pdo): void {
  static $ready=[];$key=spl_object_id($pdo);if(isset($ready[$key]))return;
  class_groups_schema($pdo);
  $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_timetables (
    teacher_id INT PRIMARY KEY, data_encrypted MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $ready[$key]=true;
}

function timetable_load(PDO $pdo, int $teacher): array {
  timetable_schema($pdo);
  $st=$pdo->prepare('SELECT data_encrypted FROM teacher_timetables WHERE teacher_id=?');
  $st->execute([$teacher]);
  $encrypted=$st->fetchColumn();
  $data=[];
  if ($encrypted!==false) {
    $data=timetable_decode((string)$encrypted);
  }
  $data=timetable_defaults($data);
  $classNames=$pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>''")->fetchAll(PDO::FETCH_COLUMN);
  $mapping=timetable_mappings($pdo);
  $data['events']=timetable_legacy_lists($data['events'],$classNames,array_keys($mapping['teachers']));
  $data['_teacher_id']=$teacher;
  return $data;
}

function timetable_defaults(array $data): array {
  // An explicitly saved opt-out must survive reimports and page visits.
  return array_replace(['enabled'=>true,'auto_refresh'=>true,'events'=>[],'rooms'=>[],'classes'=>[],'feed_url'=>'','feed_user'=>'','feed_password'=>'','imported_at'=>''], $data);
}

function timetable_decode(string $encrypted): array {
  $plain=secret_decrypt($encrypted);
  if($plain===null) throw new RuntimeException('Stundenplandaten können nicht entschlüsselt werden. Konfigurationsschlüssel prüfen.');
  return timetable_defaults(json_decode($plain,true,512,JSON_THROW_ON_ERROR));
}

/** Three visible lessons, with a movable window inside one local calendar day. */
function timetable_preview(array $data, string $date='', ?int $offset=null, ?int $now=null): array {
  $now=$now ?? time();$zone=new DateTimeZone('Europe/Vienna');
  $today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
  $selected=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
  if(!$selected || $selected->format('Y-m-d')!==$date){$date=$today;$selected=new DateTimeImmutable($today,$zone);}
  $events=array_values(array_filter($data['events'],fn($e)=>(new DateTimeImmutable('@'.$e['start']))->setTimezone($zone)->format('Y-m-d')===$date));
  usort($events,fn($a,$b)=>($a['start']<=>$b['start']) ?: strcmp($a['id'],$b['id']));
  $total=count($events);
  if($offset===null){
    $offset=0;
    if($date===$today){
      $offset=max(0,$total-3);
      foreach($events as $i=>$event)if($event['end']>$now){$offset=$i;break;}
    }
  }
  $offset=max(0,min($offset,max(0,$total-1)));
  return ['date'=>$selected,'offset'=>$offset,'total'=>$total,'events'=>array_slice($events,$offset,3),
    'previous'=>$offset>0?$offset-1:null,'next'=>$offset+3<$total?$offset+1:null];
}

function timetable_has_import(array $data): bool {
  // A valid empty calendar is still a completed import.
  return ($data['imported_at'] ?? '')!=='' || !empty($data['events']);
}

function timetable_save(PDO $pdo, int $teacher, array $data): void {
  $st=$pdo->prepare('INSERT INTO teacher_timetables(teacher_id,data_encrypted) VALUES(?,?) ON DUPLICATE KEY UPDATE data_encrypted=VALUES(data_encrypted)');
  $st->execute([$teacher,secret_encrypt(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))]);
}

function timetable_import_data(array $data, string $ics, bool $fromFile=false): array {
  $data['events']=timetable_parse($ics);
  $data['imported_at']=gmdate('c');
  // A scheduled URL fetch must not overwrite an explicitly uploaded file.
  if($fromFile)$data['auto_refresh']=false;
  return $data;
}

function timetable_text(string $value): string {
  return str_replace(['\\n','\\N','\\,','\\;','\\\\'], ["\n","\n",',',';','\\'], $value);
}

/** Repair the old parser's misplaced teacher tokens for display, without rewriting imports. */
function timetable_legacy_lists(array $events, array $actualClasses=[], array $knownTeachers=[]): array {
  $teachers=array_fill_keys($knownTeachers,true);
  foreach($events as $event) {
    foreach(($event['teachers'] ?? []) as $code)$teachers[$code]=true;
    if(isset($event['description_format']))continue;
    $tail=[]; $hasClass=false;
    foreach($event['classes'] as $code) {
      if(preg_match('/\d/u',$code)){$hasClass=true;$tail=[];}
      elseif(preg_match('/^[A-Z]{4}$/D',$code))$tail[]=$code;
      else $tail=[];
    }
    // Legacy WebUntis staff meetings: numbered classes followed by a staff list.
    // The first importer did not store a teachers field at all.
    if($hasClass && count($tail)>=2)foreach($tail as $code)$teachers[$code]=true;
  }
  foreach($actualClasses as $class)unset($teachers[$class]);
  foreach($events as &$event) {
    if(isset($event['description_format']))continue;
    $classes=[];$codes=$event['teachers'] ?? [];
    foreach($event['classes'] as $code) {
      if(isset($teachers[$code]))$codes[]=$code;
      else $classes[]=$code;
    }
    $event['classes']=$classes;$event['teachers']=array_values(array_unique($codes));
  }
  unset($event);
  return $events;
}

function timetable_date(string $key, string $value): ?DateTimeImmutable {
  if (str_contains($key,'VALUE=DATE') || preg_match('/^\d{8}$/D',$value)) return null;
  $zone='Europe/Vienna';
  if (preg_match('/(?:^|;)TZID="?([^;"\r\n]+)"?/',$key,$m)) $zone=$m[1];
  if (str_ends_with($value,'Z')) { $zone='UTC'; $value=substr($value,0,-1); }
  $date=DateTimeImmutable::createFromFormat('!Ymd\THis',$value,new DateTimeZone($zone));
  $errors=DateTimeImmutable::getLastErrors();
  if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Ungültiges Termindatum in der iCal-Datei.');
  return $date;
}

/** WebUntis separates each list with semicolons and the two lists with whitespace. */
function timetable_description(string $description): array {
  $description=trim(preg_replace('/\s*;\s*/u',';',timetable_text($description)));
  if($description==='') return ['classes'=>[],'teachers'=>[]];
  $parts=preg_split('/\s+/u',$description,2);
  if(count($parts)===2) {
    $classes=explode(';',$parts[0]);
    $teachers=preg_split('/[;\s,]+/u',$parts[1],-1,PREG_SPLIT_NO_EMPTY);
  } else {
    // Teacher-only appointments (e.g. office hours) contain no class block.
    $classes=[];
    $teachers=explode(';',$parts[0]);
  }
  return ['classes'=>array_values(array_unique(array_filter($classes,fn($v)=>$v!==''))),
    'teachers'=>array_values(array_unique(array_filter($teachers,fn($v)=>$v!=='')))];
}

function timetable_parse(string $ics): array {
  if (strlen($ics)>2097152 || !str_contains($ics,'BEGIN:VCALENDAR') || !str_contains($ics,'END:VCALENDAR')) throw new RuntimeException('Bitte eine vollständige iCal-Datei bis 2 MB verwenden.');
  $ics=preg_replace('/\n[ \t]/','',str_replace(["\r\n","\r"],"\n",$ics));
  $events=[]; $event=null; $seen=[];
  foreach (explode("\n",$ics) as $line) {
    if ($line==='BEGIN:VEVENT') { if($event!==null) throw new RuntimeException('Ungültiger Kalenderaufbau.'); $event=[]; continue; }
    if ($line==='END:VEVENT' && $event!==null) {
      if (isset($event['RRULE']) || isset($event['RECURRENCE-ID']) || isset($event['RDATE']) || isset($event['EXDATE'])) throw new RuntimeException('Bitte einen WebUntis-Export mit einzelnen Terminen verwenden; Wiederholungsregeln werden nicht unterstützt.');
      if (($event['STATUS'][1] ?? '')==='CANCELLED') { $event=null; continue; }
      if (!isset($event['UID'],$event['DTSTART'],$event['DTEND'])) throw new RuntimeException('Ein Termin enthält keine Kennung oder Anfangs-/Endzeit.');
      $start=timetable_date(...$event['DTSTART']); $end=timetable_date(...$event['DTEND']);
      if ($start && $end) {
        if ($end<=$start) throw new RuntimeException('Terminende muss nach dem Beginn liegen.');
        $id=hash('sha256',$event['UID'][1]);
        if(isset($seen[$id])) throw new RuntimeException('Doppelte Terminkennung. Bitte einen eindeutigen Export verwenden.');
        $seen[$id]=true;
        $description=$event['DESCRIPTION'][1] ?? '';
        $people=timetable_description($description);
        $events[]=['id'=>$id,'start'=>$start->getTimestamp(),'end'=>$end->getTimestamp(),
          'subject'=>mb_substr(timetable_text($event['SUMMARY'][1] ?? ''),0,240),
          'room'=>mb_substr(timetable_text($event['LOCATION'][1] ?? ''),0,120),
          'classes'=>$people['classes'], 'teachers'=>$people['teachers'],
          'description'=>$description, 'description_format'=>2];
        if(count($events)>2000) throw new RuntimeException('Maximal 2000 Termine je Import.');
      }
      $event=null; continue;
    }
    if ($event===null || !str_contains($line,':')) continue;
    [$key,$value]=explode(':',$line,2); $name=explode(';',$key,2)[0];
    $event[$name]=[$key,$value];
  }
  if ($event!==null) throw new RuntimeException('Unvollständiger Unterrichtstermin.');
  usort($events,fn($a,$b)=>$a['start']<=>$b['start']);
  return $events;
}

/** Manual or scheduled fetch. No redirects, private IPs or arbitrary hosts. */
function timetable_fetch(string $url, string $user='', string $password=''): string {
  if (!function_exists('curl_init')) throw new RuntimeException('Für den URL-Abruf wird die PHP-Erweiterung cURL benötigt. Alternativ iCal-Datei hochladen.');
  $parts=parse_url($url); $host=strtolower((string)($parts['host'] ?? ''));
  if (($parts['scheme'] ?? '')!=='https' || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.webuntis\.com$/D',$host)
    || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port']!==443)) {
    throw new RuntimeException('Nur HTTPS-iCal-Adressen von *.webuntis.com ohne Weiterleitungen sind zugelassen. Alternativ Datei hochladen.');
  }
  $ips=gethostbynamel($host) ?: [];
  if (!$ips) throw new RuntimeException('WebUntis-Server konnte nicht aufgelöst werden.');
  foreach($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Unzulässige Serveradresse.');
  $body=''; $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],
    CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$body) { if(strlen($body)+strlen($chunk)>2097152) return 0; $body.=$chunk; return strlen($chunk); }]);
  if($user!=='' || $password!=='') curl_setopt_array($ch,[CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$user.':'.$password]);
  $ok=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);
  if($ok===false || $status!==200) throw new RuntimeException('Abruf fehlgeschlagen. Adresse, Freigabe und Zugangsdaten prüfen; keine Details werden protokolliert.');
  return $body;
}

function timetable_current(array $data, ?int $now=null): array {
  $now=$now ?? time();
  return array_values(array_filter($data['events'],fn($e)=>$e['start']<=$now && $now<$e['end']));
}

function timetable_targets(PDO $pdo, array $data, array $events): array {
  $global=timetable_mappings($pdo);
  $roomIds=[]; $unknownRoom=false;
  foreach($events as $event) {
    $owners=[];
    foreach(($event['teachers'] ?? []) as $code) {
      $owner=(int)($global['teachers'][$code] ?? 0);
      if($owner) $owners[]=$owner;
    }
    if($owners && !in_array((int)($data['_teacher_id'] ?? 0),$owners,true)) return ['room'=>0,'class'=>''];
    $room=(int)($global['_configured'] ? ($global['rooms'][$event['room']] ?? 0) : ($data['rooms'][$event['room']] ?? 0));
    if($room<=0) $unknownRoom=true;
    else $roomIds[$room]=true;
  }
  $room=!$unknownRoom && count($roomIds)===1 ? (int)array_key_first($roomIds) : 0;
  if($room){$st=$pdo->prepare('SELECT id FROM rooms WHERE id=? AND is_active=1');$st->execute([$room]);if(!$st->fetchColumn())$room=0;}
  $class=timetable_class_scope($pdo,$data,$events)['class'];
  return ['room'=>$room,'class'=>$class];
}

/** Explicit group aliases override whole-class aliases; never infer a/b from spelling. */
function timetable_class_scope(PDO $pdo,array $data,array $events): array {
  $empty=['class'=>'','group_ids'=>[]];$global=timetable_mappings($pdo);
  $aliases=[];$allGroups=[];
  foreach(class_groups_list($pdo) as $g){
    $allGroups[$g['class_name']][]=(int)$g['id'];
    if($g['webuntis_code']!==null)$aliases[$g['webuntis_code']]=$g;
  }
  $classes=[];$selected=[];$whole=false;$unknown=!$events;
  foreach($events as $event){
    $owners=[];foreach(($event['teachers']??[]) as $code)if(!empty($global['teachers'][$code]))$owners[]=(int)$global['teachers'][$code];
    if($owners && !in_array((int)($data['_teacher_id']??0),$owners,true))return $empty;
    if(empty($event['classes']))$unknown=true;
    foreach(($event['classes']??[]) as $code){
      if(isset($aliases[$code])){$g=$aliases[$code];$classes[$g['class_name']]=true;$selected[(int)$g['id']]=true;}
      else{
        $class=(string)($global['_configured']?($global['classes'][$code]??''):($data['classes'][$code]??''));
        if($class==='')$unknown=true;else{$classes[$class]=true;$whole=true;}
      }
    }
  }
  if($unknown || count($classes)!==1)return $empty;
  $class=(string)array_key_first($classes);
  $st=$pdo->prepare("SELECT 1 FROM users WHERE role='student' AND class_name=? LIMIT 1");$st->execute([$class]);if(!$st->fetchColumn())return $empty;
  $ids=array_map('intval',array_keys($selected));sort($ids);
  if($whole || (count($allGroups[$class]??[])>1 && !array_diff($allGroups[$class],$ids)))$ids=[];
  return ['class'=>$class,'group_ids'=>$ids];
}

function timetable_mappings(PDO $pdo): array {
  $st=$pdo->prepare('SELECT v FROM app_settings WHERE k=?');
  $st->execute(['webuntis_mappings_v1']);
  $json=$st->fetchColumn();
  $data=$json===false ? [] : json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
  return array_replace(['rooms'=>[],'classes'=>[],'teachers'=>[]],$data,['_configured'=>$json!==false]);
}

/** Validate every central destination, not just the options rendered by the admin UI. */
function timetable_save_mappings(PDO $pdo, array $mapping): void {
  $rooms=array_map('intval',$pdo->query('SELECT id FROM rooms')->fetchAll(PDO::FETCH_COLUMN));
  $classes=$pdo->query("SELECT DISTINCT class_name FROM users WHERE role='student' AND class_name<>''")->fetchAll(PDO::FETCH_COLUMN);
  $teachers=array_map('intval',$pdo->query("SELECT id FROM users WHERE role='teacher'")->fetchAll(PDO::FETCH_COLUMN));
  $clean=['rooms'=>[],'classes'=>[],'teachers'=>[]];
  foreach($clean as $kind=>$_) {
    foreach(($mapping[$kind] ?? []) as $source=>$target) {
      $source=trim((string)$source);
      if($source==='' || mb_strlen($source)>120) throw new RuntimeException('Kürzel muss 1 bis 120 Zeichen enthalten.');
      if($kind==='classes') {
        if($target!=='' && !in_array($target,$classes,true)) throw new RuntimeException('Ungültige Zielklasse.');
      } else {
        $target=(int)$target;
        if($target!==0 && !in_array($target,$kind==='rooms'?$rooms:$teachers,true)) throw new RuntimeException('Ungültiger Zielraum oder ungültige Lehrkraft.');
      }
      $clean[$kind][$source]=$target;
    }
  }
  $st=$pdo->prepare('INSERT INTO app_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
  $st->execute(['webuntis_mappings_v1',json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
