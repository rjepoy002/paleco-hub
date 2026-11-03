<?php
namespace App\Services;
final class GoogleVisionTransport implements VisionTransport {
    public function text(string $path): string {
        $file=realpath(env('GOOGLE_APPLICATION_CREDENTIALS'));$webRoot=realpath(dirname(ROOT));
        if(!$file || !is_file($file) || ($webRoot&&str_starts_with(strtolower(str_replace('\\','/',$file)),strtolower(str_replace('\\','/',$webRoot)).'/')))throw new \RuntimeException('Vision credentials are unavailable or not stored outside the web root. Enter the date manually.');
        try{
            $json=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            if(($json['type'] ?? '')!=='service_account'||empty($json['private_key'])||empty($json['client_email'])||($json['token_uri'] ?? '')!=='https://oauth2.googleapis.com/token')throw new \RuntimeException('Invalid service account configuration.');
            $http=new \GuzzleHttp\Client(['timeout'=>20,'connect_timeout'=>5,'allow_redirects'=>false,'verify'=>true]);
            $credentials=new \Google\Auth\Credentials\ServiceAccountCredentials('https://www.googleapis.com/auth/cloud-vision',$json);
            $handler=\Google\Auth\HttpHandler\HttpHandlerFactory::build($http,false);$token=$credentials->fetchAuthToken($handler);
            if(empty($token['access_token']))throw new \RuntimeException('No access token.');
            $response=$http->post('https://vision.googleapis.com/v1/images:annotate',['headers'=>['Authorization'=>'Bearer '.$token['access_token']],'json'=>['requests'=>[['image'=>['content'=>base64_encode(file_get_contents($path))],'features'=>[['type'=>'DOCUMENT_TEXT_DETECTION']],'imageContext'=>['languageHints'=>['en']]]]]]);
            $body=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);$result=$body['responses'][0] ?? [];
            if(isset($result['error']))throw new \RuntimeException('Provider annotation error.');
            return (string)($result['fullTextAnnotation']['text'] ?? $result['textAnnotations'][0]['description'] ?? '');
        }catch(\Throwable $ex){throw new \RuntimeException('Google Vision is unavailable, timed out, or rejected the request/quota. Enter the date manually and ask an administrator to check the provider.');}
    }
}
