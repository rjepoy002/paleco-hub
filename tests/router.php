<?php
// CLI development server router used only by the isolated integration tests.
if (PHP_SAPI!=='cli-server') { http_response_code(404); exit; }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$prefix='/paleco-hub';
if(in_array($path,[$prefix.'/sw.js',$prefix.'/manifest.json'],true)){header('Content-Type: '.(str_ends_with($path,'.js')?'application/javascript':'application/json'));readfile(dirname(__DIR__).'/public/'.basename($path));return;}
if (str_starts_with($path,$prefix.'/assets/')) {
    $name=basename($path); $file=dirname(__DIR__).'/public/assets/'.$name;
    if (is_file($file)) { $extension=pathinfo($file,PATHINFO_EXTENSION); header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','png'=>'image/png'][$extension] ?? 'application/octet-stream')); readfile($file); return; }
}
require dirname(__DIR__).'/index.php';
