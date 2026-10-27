<?php
namespace App\Services;
final class CurlWordPressTransport implements WordPressTransport {
    public function request(array $connection,string $method,string $path,array $data=[],?array $file=null): array {
        if (!in_array($method,['GET','POST'],true) || !preg_match('~^/(?:users/me|categories(?:/\d+)?|posts(?:/\d+)?|media(?:/\d+)?)(?:\?[^\r\n]*)?$~D',$path)) throw new WordPressFailure('Unsupported WordPress API request.');
        $base=rtrim($connection['rest_url'],'/'); [$host,$ips]=WordPress::validateUrl($base);
        $password=WordPress::decrypt($connection['password_encrypted']);
        $ch=curl_init($base.$path); $ip=$ips[0]; if (str_contains($ip,':')) $ip='['.$ip.']';
        $headers=['Accept: application/json'];
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$connection['username'].':'.$password,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if ($method==='POST') {
            curl_setopt($ch,CURLOPT_POST,true);
            if ($file) {
                Uploads::validate($file['path']);
                $data['file']=new \CURLFile($file['path'],$file['mime'],$file['name']);
                curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
            } else { $headers[]='Content-Type: application/json'; curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
        }
        curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
        $size=0; $body=''; curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$chunk) use (&$size,&$body) { $size+=strlen($chunk); if ($size>2*1024*1024) return 0; $body.=$chunk; return strlen($chunk); });
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok===false || !$status) throw new WordPressFailure('WordPress could not be reached or timed out. Retry to reconcile the result before another write.', $method==='POST');
        return ['status'=>$status,'body'=>$body];
    }
}
