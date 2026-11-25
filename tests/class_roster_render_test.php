<?php
declare(strict_types=1);
function h(?string $value):string{return htmlspecialchars($value??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path):string{return $path;}
function csrf_token():string{return 'test-csrf';}
function roster_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$class='1HLS';$u=['id'=>23];$classHomeRoom=null;
$view=['automatic'=>true,'manual_id'=>0,'manual_override'=>false,'uses_timetable'=>true,'filter_query'=>'','next_change'=>null,'now'=>time(),'token'=>'scope-test','group_ids'=>[1],'label'=>'Gruppe a','note'=>'','groups'=>[['id'=>1,'name'=>'a'],['id'=>2,'name'=>'b']]];
$students=[
 ['id'=>1,'last_name'=>'Adler','first_name'=>'Ada','username'=>'private-login-1','is_active'=>1,'room_name'=>'Raum H','updated_at'=>'2026-11-25 08:00:00'],
 ['id'=>2,'last_name'=>'Bauer','first_name'=>'Ben','username'=>'private-login-2','is_active'=>1,'room_name'=>null,'updated_at'=>null],
 ['id'=>3,'last_name'=>'<script>','first_name'=>'Test','username'=>'private-login-3','is_active'=>0,'room_name'=>null,'updated_at'=>null]
];
ob_start();require __DIR__.'/../www/partials/class_roster.php';$html=ob_get_clean();
libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);$xp=new DOMXPath($dom);
roster_check($xp->query('//table[@id="class-table"]/thead/tr/th')->length===4,'roster has only number, name, room and presence columns');
roster_check(!str_contains($html,'private-login-') && !str_contains($html,'<th scope="col">Gruppe'),'usernames and group column are not rendered');
roster_check($xp->query('//details[contains(@class,"class-roster__actions") and not(@open)]')->length===1,'bulk actions collapsed by default');
roster_check($xp->query('//nav[@aria-label="Gruppe wechseln"]/a')->length===4,'group switch remains visible during automatic timetable filtering');
roster_check($xp->query('//nav[@aria-label="Gruppe wechseln"]/a[@aria-current="page"]')->item(0)->textContent==='Stundenplan','automatic mode is visibly selected');
roster_check($xp->query('//tr[@data-student-id]')->length===3,'all students remain available without pagination');
roster_check($xp->query('//strong[@data-present-count]')->item(0)->textContent==='2' && $xp->query('//strong[@data-unbooked-count]')->item(0)->textContent==='1','missing-room count excludes absent students');
roster_check($xp->query('//button[@value="mark_inactive"]')->length===2 && $xp->query('//button[@value="mark_active"]')->length===1,'presence buttons retain correct server actions');
roster_check($xp->query('//form')->length===$xp->query('//form/input[@name="csrf" and @value="test-csrf"]')->length && $xp->query('//form')->length===$xp->query('//form/input[@name="group_scope" and @value="scope-test"]')->length,'every action retains CSRF and group scope');
roster_check($xp->query('//td[contains(@class,"class-roster__name")]')->item(2)->textContent==='<script>, Test' && !str_contains($html,'<script>, Test'),'names remain escaped');
roster_check(str_contains($html,'auch bei aktiver Namenssuche') && $xp->query('//button[@value="book_home_room" and @disabled]')->length===1,'bulk scope is explicit and missing home room disables booking');
foreach(['all'=>'Ganze Klasse','2'=>'b'] as $selection=>$expected){
  $view['automatic']=false;$view['manual_override']=true;$view['manual_id']=$selection==='all'?0:2;$view['filter_query']=(string)$selection;
  ob_start();require __DIR__.'/../www/partials/class_roster.php';$manualHtml=ob_get_clean();
  $manualDom=new DOMDocument();$manualDom->loadHTML('<?xml encoding="UTF-8">'.$manualHtml,LIBXML_NONET);$manualXp=new DOMXPath($manualDom);
  roster_check($manualXp->query('//nav[@aria-label="Gruppe wechseln"]/a[@aria-current="page"]')->item(0)->textContent===$expected,'manual choice '.$selection.' is visibly selected');
  roster_check(str_contains($manualHtml,'&amp;group='.$selection),'manual choice '.$selection.' is retained in live connection');
}
echo "ALL ROSTER RENDER TESTS PASSED\n";
