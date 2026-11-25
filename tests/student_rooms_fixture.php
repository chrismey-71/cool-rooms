<?php
declare(strict_types=1);
$u=['first_name'=>'Alex','class_name'=>'1HLS'];
$rooms=[];$roomGroups=[];
foreach([
  ['floor','EG','#2F6F3A',['Raum A','Raum B','Raum C','Raum D','Raum E','Raum F','Raum G','Raum H']],
  ['floor','1. OG','#175CD3',['Raum I','Raum J','Raum K','Raum L','Raum M','Raum N','Raum O','Raum P','Computerraum 1','Computerraum 2']],
  ['floor','2. OG','#B54708',['Raum Q','Raum R','Raum S','Raum T','Musik / Kreativwerkstatt','Büro & Beratung','Lernatelier','Bibliothek']],
  ['location','Außen','#0F766E',['Schulhof','Sportplatz','Garten']],
  ['function','Außer Haus','#344054',['Exkursion','Praktikum']]
] as [$type,$name,$color,$names]){
  $group=['type'=>$type,'name'=>$name,'color'=>$color,'rooms'=>[]];
  foreach($names as $label){
    $id=count($rooms)+1;
    $room=['id'=>$id,'name'=>$label,'capacity'=>$type==='floor'?24:null,'occupied'=>$id===2?24:($id*3%19),'group_type'=>$type,'group_name'=>$name,'is_class_room'=>$id===3?1:0];
    $rooms[]=$room;$group['rooms'][]=$room;
  }
  $roomGroups[]=$group;
}
$classRoom=['id'=>3,'name'=>'Raum C'];
$current=['id'=>1,'name'=>'Raum A','updated_at'=>'2026-11-25 08:00:00'];
