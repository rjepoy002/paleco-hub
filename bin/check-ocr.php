<?php
/** Safe, local preflight. A poster argument explicitly opts in to an OCR API request. */
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
use App\Services\{PosterOcr,GoogleVisionTransport,InterruptionDates};
echo json_encode((new App\Services\OcrUsage())->status(),JSON_UNESCAPED_SLASHES).PHP_EOL;
if($error=PosterOcr::configurationError()){fwrite(STDERR,$error.PHP_EOL);exit(1);}
echo "Google Vision local configuration is valid. API access/billing have not been tested.\n";
if(!isset($argv[1])){echo "To verify real recognition (may incur existing provider charges): php bin/check-ocr.php PATH_TO_POSTER\n";exit;}
try{
    $result=InterruptionDates::extract((new GoogleVisionTransport())->text($argv[1]));
    echo $result['message'].PHP_EOL;
    if($result['status']!=='valid')exit(2);
    echo 'Normalized date: '.$result['date'].PHP_EOL;
    echo 'Title: '.App\Services\Announcement::dateTitle($result['date']).PHP_EOL;
}catch(App\Services\OcrFailure $ex){fwrite(STDERR,$ex->getMessage().PHP_EOL);exit(1);}
catch(Throwable $ex){fwrite(STDERR,"OCR could not run. Verify the poster and database/migrations; OCR requests require durable usage tracking.\n");exit(1);}
