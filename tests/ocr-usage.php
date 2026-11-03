<?php
use App\Services\{OcrUsage,GoogleVisionTransport,PosterOcr,VisionTransport};
use App\Models\DB;
(static function(){
$priorLimit=getenv('OCR_MONTHLY_LIMIT');$priorEnvLimit=$_ENV['OCR_MONTHLY_LIMIT'] ?? null;
try{
    putenv('OCR_MONTHLY_LIMIT');unset($_ENV['OCR_MONTHLY_LIMIT']);$usage=new OcrUsage();$payload=['requests'=>[['image'=>['content'=>'fixture'],'features'=>[['type'=>'DOCUMENT_TEXT_DETECTION']]]]];
    check($usage->status()['limit']===900,'monthly application default is 900 units');
    check(OcrUsage::units($payload)===1,'one image with DOCUMENT_TEXT_DETECTION reserves one unit');
    $batch=['requests'=>[$payload['requests'][0],$payload['requests'][0]]];$batch['requests'][0]['features'][]=['type'=>'TEXT_DETECTION'];
    check(OcrUsage::units($batch)===3,'batch accounting sums image-feature pairs conservatively without using maxResults');
    rejects(fn()=>OcrUsage::units(['requests'=>[['image'=>['content'=>'x'],'features'=>[['type'=>'UNKNOWN']]]]]),'unknown future features fail closed before dispatch');
    DB::query('DELETE FROM ocr_monthly_usage');putenv('OCR_MONTHLY_LIMIT=2');
    $mock=new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(200,[],'{}'),new GuzzleHttp\Exception\ConnectException('test timeout',new GuzzleHttp\Psr7\Request('POST','https://vision.example.invalid'))]);
    $http=new GuzzleHttp\Client(['handler'=>$mock]);
    GoogleVisionTransport::annotate($http,$payload,'fake-token');
    try{GoogleVisionTransport::annotate($http,$payload,'fake-token');}catch(GuzzleHttp\Exception\ConnectException $ex){}
    check($usage->status()['used']===2,'retry and network timeout each retain a reservation');
    rejects(fn()=>GoogleVisionTransport::annotate($http,$payload,'fake-token'),'exhausted allowance blocks before dispatch');
    check($usage->status()['remaining']===0&&$mock->count()===0,'exhaustion cannot issue another HTTP request');
    putenv('OCR_MONTHLY_LIMIT=4');$failed=new GuzzleHttp\Client(['handler'=>new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(500)])]);
    try{GoogleVisionTransport::annotate($failed,$payload,'fake-token');}catch(GuzzleHttp\Exception\RequestException $ex){}
    check($usage->status()['used']===3,'failed HTTP response never refunds a potentially billable request');
    DB::connection()->beginTransaction();$usage->reserve($payload);DB::connection()->rollBack();
    check($usage->status()['used']===4,'publishing rollback cannot refund independent usage reservations');
    // Cached scans never enter the transport, even once the allowance is exhausted.
    putenv('OCR_MONTHLY_LIMIT=5');
    $transport=new class implements VisionTransport{public int $calls=0;public function text(string $path):string{$this->calls++;$http=new GuzzleHttp\Client(['handler'=>new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(200)])]);GoogleVisionTransport::annotate($http,['requests'=>[['image'=>['content'=>'fixture'],'features'=>[['type'=>'DOCUMENT_TEXT_DETECTION']]]]],'fake');return 'Interruption Date: October 25, 2026 Sunday';}};
    $path=ROOT.'/storage/ocr-usage-cache.png';copy(ROOT.'/public/assets/paleco-logo.png',$path);file_put_contents($path,'usage fixture',FILE_APPEND);
    $scan=new PosterOcr($transport);$scan->scan($path);$scan->scan($path);unlink($path);
    check($transport->calls===1&&$usage->status()['used']===5,'cache hit avoids transport dispatch and additional usage');
    // Re-create a valid, distinct poster to exercise exhausted-cache misses through PosterOcr.
    copy(ROOT.'/public/assets/paleco-logo.png',$path);file_put_contents($path,'exhausted fixture',FILE_APPEND);
    $fallback=$scan->scan($path);unlink($path);
    check($fallback['status']==='unavailable'&&str_contains($fallback['message'],'Monthly OCR allowance'),'quota exhaustion gives actionable per-poster manual fallback');
    PosterOcr::assertDate([$fallback],'2026-10-25','manual');check(true,'manual interruption-date entry remains valid when the allowance is exhausted');
    rejects(fn()=>PosterOcr::assertDate([$fallback],'2026-10-25','ocr'),'exhaustion cannot approve an absent automatically detected date');
    // Session-local DB clock tests the exact Manila boundary without changing server time.
    putenv('OCR_MONTHLY_LIMIT=900');DB::query('DELETE FROM ocr_monthly_usage');
    $clock=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT','3306').';dbname='.env('DB_DATABASE'),env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $clock->exec('SET timestamp=1796054399');$monthly=new OcrUsage($clock);$old=$monthly->status()['month'];$monthly->reserve($payload);
    $clock->exec('SET timestamp=1796054400');$new=$monthly->status();
    check($old==='2026-11'&&$new['month']==='2026-12'&&$new['used']===0,'counter rolls over at midnight Asia/Manila, retaining previous month');
    $monthly->reserve($payload);check($monthly->status()['used']===1,'new month gets its own durable allowance');
    check((int)DB::query('SELECT COUNT(*) FROM ocr_monthly_usage')->fetchColumn()>=2,'rollover preserves historical month counters');
    DB::query('DELETE FROM ocr_monthly_usage');putenv('OCR_MONTHLY_LIMIT=25');
    $workers=[];$environment=array_merge(getenv(),$_ENV,['DB_DATABASE'=>env('DB_DATABASE'),'OCR_MONTHLY_LIMIT'=>'25','OCR_PROVIDER'=>'none','GOOGLE_APPLICATION_CREDENTIALS'=>'']);
    for($i=0;$i<5;$i++)$workers[]=proc_open([PHP_BINARY,ROOT.'/tests/ocr-usage-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT,$environment);
    foreach($workers as $worker)check(proc_close($worker)===0,'parallel OCR reservation worker completed');
    check($usage->status()['used']===25,'100 parallel-process attempts cannot exceed the 25-unit allowance');
    putenv('OCR_MONTHLY_LIMIT=0');rejects(fn()=>$usage->reserve($payload),'zero allowance disables uncached requests');
    putenv('OCR_MONTHLY_LIMIT=invalid');rejects(fn()=>$usage->reserve($payload),'invalid limit configuration fails closed');
    putenv('OCR_MONTHLY_LIMIT=900');
    DB::query('RENAME TABLE ocr_monthly_usage TO ocr_monthly_usage_offline');
    try{
        $queued=new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(200)]);
        $offline=new GuzzleHttp\Client(['handler'=>$queued]);
        rejects(fn()=>GoogleVisionTransport::annotate($offline,$payload,'fake'),'missing usage table fails closed before OCR dispatch');
        check($queued->count()===1&&!$usage->status()['available'],'usage outage preserves queued HTTP request and reports tracking unavailable');
    }finally{DB::query('RENAME TABLE ocr_monthly_usage_offline TO ocr_monthly_usage');}
    DB::query('DELETE FROM ocr_monthly_usage');
}finally{putenv($priorLimit===false?'OCR_MONTHLY_LIMIT':'OCR_MONTHLY_LIMIT='.$priorLimit);if($priorEnvLimit===null)unset($_ENV['OCR_MONTHLY_LIMIT']);else $_ENV['OCR_MONTHLY_LIMIT']=$priorEnvLimit;}

})();
