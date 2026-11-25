<?php
declare(strict_types=1);
require __DIR__.'/../www/lib/version.php';
$root=__DIR__.'/../docs';$html=file_get_contents($root.'/index.html');
libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML($html,LIBXML_NONET);$xp=new DOMXPath($dom);
function site_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
site_check(str_contains($html,'Version '.APP_VERSION) && str_contains($html,'/releases/tag/v'.APP_VERSION),'project page and download target match application version');
foreach($xp->query('//*[@src or @href]') as $node){
  $path=$node->getAttribute($node->hasAttribute('src')?'src':'href');
  if($path==='' || preg_match('~^(https?:|mailto:)~',$path))continue;
  if($path[0]==='#'){
    site_check($xp->query('//*[@id="'.substr($path,1).'"]')->length===1,'internal anchor '.$path.' exists');
    continue;
  }
  $file=$root.'/'.parse_url($path,PHP_URL_PATH);
  site_check(is_file($file),'local asset '.$path.' exists');
  if($node->nodeName==='img' && str_ends_with((string)parse_url($path,PHP_URL_PATH),'.jpg')){
    [$width,$height]=getimagesize($file);
    site_check((int)$node->getAttribute('width')===$width && (int)$node->getAttribute('height')===$height,'screenshot dimensions match '.$path);
  }
}
site_check(str_contains($html,'WebUntis') && str_contains($html,'Klassengruppe') && str_contains($html,'Raumwahl fürs Smartphone'),'release highlights cover new functionality');
site_check(str_contains($html,'Weitere Ansichten stammen aus Version 1.21'),'older screenshot versions are identified accurately');
echo "ALL PROJECT SITE TESTS PASSED\n";
