<?php
use App\Services\{PosterOcr,GoogleVisionTransport,InterruptionDates,OcrFailure};
$variants=[
    'Interruption Date: October 25, 2026 | Sunday',
    "Interruption Date:\nOctober 25, 2026\nSunday",
    "INTERRUPTION\nDATE :\nOctober\n25,\n2026 | Sunday",
    'interruption   date : october  25 ,2026 | sunday',
    'Date of Interruption - Oct. 25th, 2026 Sunday',
];
foreach($variants as $text){$r=InterruptionDates::extract($text);check($r['status']==='valid'&&$r['date']==='2026-10-25','OCR spacing/label/date line variations normalize to the labelled date');}
check(InterruptionDates::extract("Interruption Date:\nUnreadable\nEvent Date: October 25, 2026")['date']===null,'date under another field cannot replace an unreadable interruption date');
check(InterruptionDates::extract('Interruption Date: October 25, 2026')['message']==='Interruption date detected: October 25, 2026','detected-date feedback includes the readable date');
$previous=[];foreach(['OCR_PROVIDER','GOOGLE_APPLICATION_CREDENTIALS'] as $key)$previous[$key]=getenv($key);
$file=tempnam(sys_get_temp_dir(),'hub-ocr-config-');$poster=ROOT.'/storage/ocr-diagnostic-fixture.png';
try{
    copy(ROOT.'/public/assets/paleco-logo.png',$poster);file_put_contents($poster,"\nOCR diagnostic",FILE_APPEND);
    putenv('OCR_PROVIDER=none');putenv('GOOGLE_APPLICATION_CREDENTIALS=');
    $r=(new PosterOcr())->scan($poster);check($r['status']==='unavailable'&&str_contains($r['message'],'OCR_PROVIDER=none'),'disabled OCR is diagnosed before any provider request');
    putenv('OCR_PROVIDER=invalid');check(str_contains(PosterOcr::configurationError(),'Unsupported OCR_PROVIDER'),'unsupported provider has actionable configuration feedback');
    putenv('OCR_PROVIDER=google_vision');check(str_contains(PosterOcr::configurationError(),'GOOGLE_APPLICATION_CREDENTIALS is missing'),'missing Google Vision credential setting is identified exactly');
    putenv('GOOGLE_APPLICATION_CREDENTIALS='.$file.'-absent');check(str_contains(PosterOcr::configurationError(),'missing or unreadable'),'missing credential file has actionable feedback');
    putenv('GOOGLE_APPLICATION_CREDENTIALS='.$poster);check(str_contains(PosterOcr::configurationError(),'outside the XAMPP htdocs'),'credentials under web root are rejected before reading them');
    putenv('GOOGLE_APPLICATION_CREDENTIALS='.$file);file_put_contents($file,'invalid secret contents');check(str_contains(GoogleVisionTransport::configurationError(),'invalid JSON'),'malformed credentials are rejected without exposing their contents');
    file_put_contents($file,json_encode(['web'=>['client_secret'=>'must never appear']]));check(str_contains(GoogleVisionTransport::configurationError(),'Google Sign-In'),'Google Sign-In credentials cannot masquerade as Vision credentials');
    $safe=new class implements App\Services\VisionTransport{public function text(string $path):string{throw new OcrFailure('Google Vision quota or rate limit was reached. Retry later or enter the interruption date manually.');}};
    $r=(new PosterOcr($safe))->scan($poster);check($r['status']==='unavailable'&&str_contains($r['message'],'quota or rate limit'),'controlled provider failures retain actionable reasons');
    App\Models\DB::query('DELETE FROM ocr_cache WHERE cache_key=?',[hash('sha256','dates-v2:mock:'.hash_file('sha256',$poster))]);
    $unsafe=new class implements App\Services\VisionTransport{public function text(string $path):string{throw new RuntimeException('Bearer credential-secret');}};
    $r=(new PosterOcr($unsafe))->scan($poster);check($r['status']==='unavailable'&&!str_contains($r['message'],'credential-secret'),'unexpected provider errors cannot leak credential details');
}finally{
    foreach($previous as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    if(is_file($file))unlink($file);if(is_file($poster))unlink($poster);
}
