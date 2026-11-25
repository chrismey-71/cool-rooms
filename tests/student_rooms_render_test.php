<?php
declare(strict_types=1);
require __DIR__.'/student_rooms_fixture.php';
require __DIR__.'/../www/lib/room_groups.php';
function h(?string $value):string{return htmlspecialchars($value??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path):string{return '/school'.$path;}
function csrf_token():string{return 'student-room-test-csrf';}
function room_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
function render_room_picker():array{
  global $u,$rooms,$roomGroups,$classRoom,$current;
  ob_start();require __DIR__.'/../www/partials/student_rooms.php';$html=ob_get_clean();
  $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);
  return [$html,new DOMXPath($dom)];
}
[$html,$xp]=render_room_picker();
room_check($xp->query('//button[@data-room-card]')->length===count($rooms),'every active room has a native booking button, usable without JavaScript');
room_check($xp->query('//form/input[@name="csrf" and @value="student-room-test-csrf"]')->length===$xp->query('//form')->length,'all booking and logout forms retain CSRF protection');
room_check($xp->query('//button[@data-room-card and @value="2" and @disabled]')->length===1,'full rooms cannot be selected');
room_check($xp->query('//button[@aria-current="location" and @value="1"]')->length===1,'current room is distinctly marked');
room_check($xp->query('//button[@value="3"]/span[contains(@class,"tags")]')->item(0)->textContent==='Klassenraum','home room remains marked independently of current room');
room_check($xp->query('//input[@type="search" and not(ancestor::form)]')->length===1,'search cannot accidentally submit a room booking');
room_check($xp->query('//div[@data-room-tools and @hidden]')->length===1 && $xp->query('//details[not(@open)]')->length===1,'JavaScript-only filters and alternative menu do not clutter fallback');
room_check($xp->query('//section[@data-room-group]')->item(0)->getAttribute('data-room-area-name')==='Etage EG','admin area order and labels remain unchanged');
room_check(str_contains($html,'/school/assets/student-rooms.js?v='),'script supports installation in a subdirectory');
$current=['id'=>2,'name'=>'Raum B'];[$html,$xp]=render_room_picker();
room_check($xp->query('//button[@value="2" and @aria-current="location" and not(@disabled)]')->length===1,'own current room stays selectable even when full');
$u['first_name']='<script>alert(1)</script>';$roomGroups[0]['rooms'][0]['name']='"><img src=x onerror=alert(1)>';
$_SESSION['via_kiosk']=true;[$html,$xp]=render_room_picker();
room_check(!str_contains($html,'<script>alert') && $xp->query('//img')->length===0,'names and filter metadata are HTML escaped');
room_check($xp->query('//form[@action="/school/logout.php"]/input[@name="to" and @value="kiosk"]')->length===1,'kiosk logout target remains unchanged');
unset($_SESSION['via_kiosk']);$rooms=[];$roomGroups=[];$classRoom=null;$current=null;[$html,$xp]=render_room_picker();
room_check(str_contains($html,'keine Räume zur Auswahl freigegeben') && str_contains($html,'Nicht eingebucht'),'empty installation has clear messages');
echo "ALL STUDENT ROOM RENDER TESTS PASSED\n";
