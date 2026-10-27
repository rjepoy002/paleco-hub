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
        return (new WordPressClient($connection))->request('GET',$path);
    }
}
