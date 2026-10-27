<?php
namespace App\Services;
final class WordPress {
    public static function validateUrl(string $url): array {
        $p=parse_url($url);
        if (!$p || ($p['scheme'] ?? '')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443)) throw new \RuntimeException('Use an HTTPS destination URL without credentials, query parameters, or custom ports.');
        $host=strtolower($p['host']);
        if (filter_var(trim($host,'[]'),FILTER_VALIDATE_IP) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/',$host)) throw new \RuntimeException('Use a public website hostname.');
        $records=dns_get_record($host,DNS_A|DNS_AAAA); $ips=[];
        foreach ($records ?: [] as $record) { $ip=$record['ip'] ?? $record['ipv6'] ?? null; if (!$ip) continue; if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('Private or reserved destination addresses are blocked.'); $ips[]=$ip; }
        if (!$ips) throw new \RuntimeException('Destination hostname could not be resolved.');
        return [$host,$ips];
    }
    public static function encrypt(string $plain): string {
        $key=base64_decode(env('APP_KEY'),true); if ($key===false || strlen($key)!==32) throw new \RuntimeException('A 32-byte server encryption key must be configured.');
        $iv=random_bytes(12); $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        if ($cipher===false) throw new \RuntimeException('Credential encryption failed.'); return base64_encode($iv.$tag.$cipher);
    }
    public static function decrypt(string $value): string {
        $key=base64_decode(env('APP_KEY'),true); $raw=base64_decode($value,true);
        if ($key===false || strlen($key)!==32 || $raw===false || strlen($raw)<28) throw new \RuntimeException('Stored credentials cannot be unlocked.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        if ($plain===false) throw new \RuntimeException('Stored credentials cannot be unlocked.'); return $plain;
    }
    public static function get(array $connection,string $path): array {
        $base=rtrim($connection['rest_url'],'/'); [$host,$ips]=self::validateUrl($base);
        $ch=curl_init($base.$path); $ip=$ips[0]; if (str_contains($ip,':')) $ip='['.$ip.']';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$connection['username'].':'.self::decrypt($connection['password_encrypted']),CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $size=0; $body=''; curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$chunk) use (&$size,&$body) { $size+=strlen($chunk); if ($size>2*1024*1024) return 0; $body.=$chunk; return strlen($chunk); });
        $ok=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok===false || $status!==200) throw new \RuntimeException('WordPress connection failed. Check the HTTPS destination, credentials, and REST API access.');
        $json=json_decode($body,true); if (!is_array($json)) throw new \RuntimeException('WordPress returned an invalid response.'); return $json;
    }
}
