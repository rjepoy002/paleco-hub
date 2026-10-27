<?php
namespace App\Services;
use App\Models\DB;
final class Auth {
    public static function cookie(string $value,int $expires): void { setcookie('hub_remember',$value,['expires'=>$expires,'path'=>parse_url(url(),PHP_URL_PATH) ?: '/','secure'=>str_starts_with(url(),'https://'),'httponly'=>true,'samesite'=>'Lax']); }
    public static function user(): ?array {
        if (!isset($_SESSION['auth']) && isset($_COOKIE['hub_remember'])) {
            $parts=explode(':',$_COOKIE['hub_remember']);
            if (count($parts)===2 && preg_match('/^[a-f0-9]{32}$/',$parts[0]) && preg_match('/^[a-f0-9]{64}$/',$parts[1])) {
                $db=DB::connection(); $db->beginTransaction();
                try {
                    $d=DB::query('SELECT * FROM remembered_devices WHERE selector=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[$parts[0]])->fetch();
                    if ($d && hash_equals($d['token_hash'],hash('sha256',$parts[1]))) {
                        $u=DB::query('SELECT * FROM users WHERE id=? AND is_active=1',[$d['user_id']])->fetch();
                        if ($u) { $token=bin2hex(random_bytes(32)); DB::query('UPDATE remembered_devices SET token_hash=? WHERE selector=?',[hash('sha256',$token),$parts[0]]); self::cookie($parts[0].':'.$token,strtotime($d['expires_at'].' UTC')); self::login($u,false); }
                    } else { if ($d) DB::query('UPDATE remembered_devices SET revoked_at=UTC_TIMESTAMP() WHERE selector=?',[$parts[0]]); self::cookie('',time()-3600); }
                    $db->commit();
                } catch (\Throwable $ex) { if ($db->inTransaction()) $db->rollBack(); throw $ex; }
            } else self::cookie('',time()-3600);
        }
        if (!isset($_SESSION['auth'])) return null;
        $u=DB::query('SELECT u.*,r.name role_name FROM login_sessions s JOIN users u ON u.id=s.user_id JOIN roles r ON r.id=u.role_id WHERE s.id=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() AND s.last_seen>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) AND u.is_active=1',[$_SESSION['auth']])->fetch();
        if (!$u) { unset($_SESSION['auth']); return null; }
        DB::query('UPDATE login_sessions SET last_seen=UTC_TIMESTAMP() WHERE id=?',[$_SESSION['auth']]); return $u;
    }
    public static function login(array $u,bool $remember): void {
        session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); $_SESSION['auth']=bin2hex(random_bytes(32));
        $device=mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device',0,255);
        DB::query('INSERT INTO login_sessions (id,user_id,device,last_seen,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 12 HOUR))',[$_SESSION['auth'],$u['id'],$device]);
        if ($remember) { $selector=bin2hex(random_bytes(16)); $token=bin2hex(random_bytes(32)); DB::query('INSERT INTO remembered_devices (selector,user_id,token_hash,device,expires_at) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))',[$selector,$u['id'],hash('sha256',$token),$device]); self::cookie($selector.':'.$token,time()+30*86400); }
        DB::audit((int)$u['id'],'auth.login');
    }
    public static function logout(int $id): void {
        DB::query('UPDATE login_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?',[$_SESSION['auth'] ?? '',$id]);
        $selector=explode(':',$_COOKIE['hub_remember'] ?? '')[0];
        DB::query('UPDATE remembered_devices SET revoked_at=UTC_TIMESTAMP() WHERE selector=? AND user_id=?',[$selector,$id]);
        DB::audit($id,'auth.logout'); self::cookie('',time()-3600); $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public static function provider(): \League\OAuth2\Client\Provider\Google {
        return new GoogleProvider(['clientId'=>env('GOOGLE_CLIENT_ID'),'clientSecret'=>env('GOOGLE_CLIENT_SECRET'),'redirectUri'=>env('GOOGLE_REDIRECT_URI',url('/auth/google/callback'))]);
    }
    public static function redirect(): never {
        if (!env('GOOGLE_CLIENT_ID') || !env('GOOGLE_CLIENT_SECRET') || !class_exists(\League\OAuth2\Client\Provider\Google::class)) throw new \RuntimeException('Google Sign-In awaits configuration. Follow the setup guide to connect this application.');
        $p=self::provider(); $auth=$p->getAuthorizationUrl(['scope'=>['openid','email','profile'],'prompt'=>'select_account']);
        $_SESSION['oauth']=['state'=>$p->getState(),'pkce'=>$p->getPkceCode(),'expires'=>time()+600,'remember'=>isset($_POST['remember'])];
        header('Location: '.$auth); exit;
    }
    public static function callback(): never {
        $o=$_SESSION['oauth'] ?? []; unset($_SESSION['oauth']);
        if (!$o || $o['expires']<time() || !is_string($_GET['state'] ?? null) || !hash_equals($o['state'],$_GET['state']) || !is_string($_GET['code'] ?? null) || isset($_GET['error'])) throw new \RuntimeException('Google Sign-In was cancelled or expired. Please start again.');
        $p=self::provider(); $p->setPkceCode($o['pkce']);
        try { $token=$p->getAccessToken('authorization_code',['code'=>$_GET['code']]); $identity=$p->getResourceOwner($token)->toArray(); }
        catch (\Throwable $ex) { throw new \RuntimeException('Google could not verify this sign-in. Please try again.'); }
        $email=strtolower(trim($identity['email'] ?? '')); $subject=$identity['sub'] ?? '';
        if (($identity['email_verified'] ?? false)!==true || !filter_var($email,FILTER_VALIDATE_EMAIL) || !is_string($subject) || !$subject) throw new \RuntimeException('A verified Google email address is required.');
        $db=DB::connection(); $db->beginTransaction();
        try {
            $u=DB::query('SELECT * FROM users WHERE email=? AND is_active=1 FOR UPDATE',[$email])->fetch();
            if (!$u || ($u['google_id']!==null && $u['google_id']!==$subject)) throw new \RuntimeException('This account has not been approved for PALECO Hub. Contact an administrator.');
            DB::query('UPDATE users SET google_id=? WHERE id=?',[$subject,$u['id']]);
            if ($u['google_id']===null) DB::audit((int)$u['id'],'auth.google_linked');
            $db->commit();
        } catch (\Throwable $ex) { $db->rollBack(); throw $ex; }
        self::login($u,(bool)$o['remember']); go('/dashboard');
    }
}
