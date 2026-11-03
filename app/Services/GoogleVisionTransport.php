<?php
namespace App\Services;
final class GoogleVisionTransport implements VisionTransport {
    public static function configurationError(): ?string {
        if(!class_exists(\Google\Auth\Credentials\ServiceAccountCredentials::class)||!class_exists(\GuzzleHttp\Client::class))return 'Google Vision dependencies are missing. Ask an administrator to run composer install in PALECO Hub.';
        if(!extension_loaded('curl')||!extension_loaded('openssl'))return 'Google Vision requires PHP cURL and OpenSSL. Enable them in XAMPP php.ini and restart Apache.';
        $setting=trim(env('GOOGLE_APPLICATION_CREDENTIALS'));
        if($setting==='')return 'GOOGLE_APPLICATION_CREDENTIALS is missing. Ask an administrator to configure a Google Vision service-account JSON file outside the web root.';
        $file=realpath($setting);$webRoot=realpath(dirname(ROOT));
        if(!$file||!is_file($file)||!is_readable($file))return 'The Google Vision credential file is missing or unreadable. Check GOOGLE_APPLICATION_CREDENTIALS and the Apache account file permissions.';
        if($webRoot&&str_starts_with(strtolower(str_replace('\\','/',$file)),strtolower(str_replace('\\','/',$webRoot)).'/'))return 'Move the Google Vision service-account JSON outside the XAMPP htdocs web root and update GOOGLE_APPLICATION_CREDENTIALS.';
        try {
            $json=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($json)||($json['type'] ?? '')!=='service_account'||!is_string($json['private_key'] ?? null)||!is_string($json['client_email'] ?? null)||!filter_var($json['client_email'],FILTER_VALIDATE_EMAIL)||empty($json['project_id'])||($json['token_uri'] ?? '')!=='https://oauth2.googleapis.com/token'||!@openssl_pkey_get_private($json['private_key']))return 'The Google Vision credential file is not a valid service-account JSON key. Google Sign-In client credentials cannot be used for OCR.';
        }catch(\Throwable $ex){return 'The Google Vision credential file contains invalid JSON. Ask an administrator to replace it with an approved service-account key.';}
        return null;
    }
    private static function providerFailure(int $code,string $reason=''): OcrFailure {
        $message=match(true){
            $reason==='SERVICE_DISABLED'=>'Google Cloud Vision API is disabled for the credential project. Ask an administrator to enable it.',
            $reason==='BILLING_DISABLED'=>'Google Vision billing is not enabled for the credential project. Ask an administrator to check its approved billing setup.',
            $code===401=>'Google Vision authentication failed. Ask an administrator to check the service-account key and system clock.',
            $code===403=>'Google Vision denied access. Check the project API, billing and service-account permissions.',
            $code===429||$code===8=>'Google Vision quota or rate limit was reached. Retry later or enter the interruption date manually.',
            $code===400||$code===3=>'Google Vision rejected this image. Try a clear JPG, PNG or WebP poster, or enter the date manually.',
            default=>'Google Vision is unavailable or timed out. Retry later or enter the interruption date manually.'
        };
        return new OcrFailure($message);
    }
    /** Shared by automatic scans and CLI; never dispatch an annotation without a committed reservation. */
    public static function annotate(\GuzzleHttp\ClientInterface $http,array $payload,string $token): \Psr\Http\Message\ResponseInterface {
        (new OcrUsage())->reserve($payload);
        return $http->request('POST','https://vision.googleapis.com/v1/images:annotate',['headers'=>['Authorization'=>'Bearer '.$token],'json'=>$payload,'allow_redirects'=>false]);
    }
    public function text(string $path): string {
        Uploads::validate($path);
        if($error=self::configurationError())throw new OcrFailure($error);
        try{
            $json=json_decode(file_get_contents(realpath(trim(env('GOOGLE_APPLICATION_CREDENTIALS')))),true,512,JSON_THROW_ON_ERROR);
            $http=new \GuzzleHttp\Client(['timeout'=>20,'connect_timeout'=>5,'allow_redirects'=>false,'verify'=>true]);
            $credentials=new \Google\Auth\Credentials\ServiceAccountCredentials('https://www.googleapis.com/auth/cloud-vision',$json);
            $handler=\Google\Auth\HttpHandler\HttpHandlerFactory::build($http,false);$token=$credentials->fetchAuthToken($handler);
            if(empty($token['access_token']))throw new OcrFailure('Google Vision authentication returned no access token. Ask an administrator to check the service account.');
            $payload=['requests'=>[['image'=>['content'=>base64_encode(file_get_contents($path))],'features'=>[['type'=>'DOCUMENT_TEXT_DETECTION']],'imageContext'=>['languageHints'=>['en']]]]];
            // Production client has no automatic retries. A retry re-enters the metered dispatch.
            $response=self::annotate($http,$payload,$token['access_token']);
            $body=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);$result=$body['responses'][0] ?? null;
            if(!is_array($result))throw new OcrFailure('Google Vision returned an incomplete OCR response. Retry or enter the date manually.');
            if(isset($result['error']))throw self::providerFailure((int)($result['error']['code'] ?? 0));
            return (string)($result['fullTextAnnotation']['text'] ?? $result['textAnnotations'][0]['description'] ?? '');
        }catch(OcrFailure $ex){throw $ex;}
        catch(\GuzzleHttp\Exception\RequestException $ex){
            $response=$ex->getResponse();$reason='';
            if($response){$body=json_decode((string)$response->getBody(),true);foreach($body['error']['details'] ?? [] as $detail){if(in_array($detail['reason'] ?? '',['SERVICE_DISABLED','BILLING_DISABLED'],true))$reason=$detail['reason'];}}
            throw self::providerFailure($response?$response->getStatusCode():0,$reason);
        }catch(\Throwable $ex){throw self::providerFailure(0);}
    }
}
