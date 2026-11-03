<?php
require dirname(__DIR__).'/app/bootstrap.php';
if(!preg_match('/^paleco_hub_test_[a-f0-9]{12}$/',env('DB_DATABASE')))exit(2);
$payload=['requests'=>[['image'=>['content'=>'fixture'],'features'=>[['type'=>'DOCUMENT_TEXT_DETECTION']]]]];
for($i=0;$i<20;$i++){try{(new App\Services\OcrUsage())->reserve($payload);}catch(App\Services\OcrFailure $ex){}}
