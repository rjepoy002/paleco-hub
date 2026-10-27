<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
ini_set('session.use_cookies','0');
session_cache_limiter('');
session_name('PALECOHUB');
use App\Services\{Announcement,WordPress};
$passed=0;
function check(bool $condition,string $name): void { global $passed; if (!$condition) throw new RuntimeException('FAIL: '.$name); echo 'PASS: '.$name."\n"; $passed++; }
function rejects(callable $action,string $name): void { try { $action(); } catch (RuntimeException $ex) { check(true,$name); return; } check(false,$name); }
check(Announcement::title("\nPower Service Advisory Final Update 10/23/26 12:00 PM\n\nRestored.")==='Power Service Advisory Final Update 10/23/26 12:00 PM','original title preserved');
check(Announcement::title("  POWER SERVICE ADVISORY  \nMessage")==='  POWER SERVICE ADVISORY  ','title whitespace and case preserved');
check(str_starts_with(Announcement::title('No original title'),'Partial Power Interruption Update'),'fallback generated');
check(Announcement::dateTitle('2026-10-25')==='Scheduled Power Interruption October 25, 2026','scheduled date title');
rejects(fn()=>Announcement::dateTitle('2026-02-30'),'invalid dates rejected'); rejects(fn()=>Announcement::dateTitle(''),'missing dates never guessed');
foreach(['http://paleco.net','https://127.0.0.1','https://[::1]','https://user:pass@paleco.net','https://paleco.net:8443','https://localhost','https://paleco.net/?token=secret'] as $unsafe) rejects(fn()=>WordPress::validateUrl($unsafe),'unsafe endpoint blocked: '.$unsafe);
$secret='test application password';$cipher=WordPress::encrypt($secret);check($cipher!==$secret && WordPress::decrypt($cipher)===$secret,'authenticated credential encryption round trip');
$raw=base64_decode($cipher);$raw[28]=chr(ord($raw[28])^1); rejects(fn()=>WordPress::decrypt(base64_encode($raw)),'tampered credentials rejected');
if (in_array('--unit',$argv,true)) { echo "$passed tests passed.\n"; exit; }
$database='paleco_hub_test_'.bin2hex(random_bytes(6));$port=18000+random_int(0,2000);$server=null;$pdo=null;$sessions=[];
$originalDatabase=env('DB_DATABASE');
function request(string $path,string $cookie='',?array $post=null,string $extraCookie=''): array {
    global $port;$ch=curl_init('http://127.0.0.1:'.$port.'/paleco-hub'.$path);$headers=[];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$headers[]=$line;return strlen($line);}]);
    if($cookie||$extraCookie)curl_setopt($ch,CURLOPT_COOKIE,($cookie?'PALECOHUB='.$cookie:'').($cookie&&$extraCookie?'; ':'').$extraCookie);
    if($post!==null) { curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post)); }
    $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$code,$body,$headers];
}
function seedSession(int $user,string $name): array {
    global $sessions;$sid=bin2hex(random_bytes(20));$auth=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
    App\Models\DB::query('INSERT INTO login_sessions (id,user_id,device,last_seen,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 12 HOUR))',[$auth,$user,$name]);
    session_id($sid);session_start();$_SESSION=['auth'=>$auth,'csrf'=>$csrf];session_write_close();$sessions[]=$sid;return [$sid,$csrf,$auth];
}
try {
    $pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306'),env('DB_USERNAME','root'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE `'.$database.'`');foreach(explode(';',file_get_contents(ROOT.'/database/schema.sql')) as $sql)if(trim($sql))$pdo->exec($sql);
    $_ENV['DB_DATABASE']=$database;
    App\Services\Migrations::run();
    App\Models\DB::query("INSERT INTO users(email,name,role_id) VALUES ('admin@example.invalid','Test Administrator',1),('publisher@example.invalid','Test Publisher',2)");
    // Session files are created through PHP's native handler, with no test bypass in application code.
    [$admin,$adminCsrf,$adminAuth]=seedSession(1,'Integration admin');[$publisher,$pubCsrf,$pubAuth]=seedSession(2,'Integration publisher');
    $environment=array_merge(getenv(),$_ENV,['DB_DATABASE'=>$database,'APP_URL'=>'http://127.0.0.1:'.$port.'/paleco-hub','GOOGLE_CLIENT_ID'=>'test-client','GOOGLE_CLIENT_SECRET'=>'test-secret','GOOGLE_REDIRECT_URI'=>'http://127.0.0.1:'.$port.'/paleco-hub/auth/google/callback']);
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,ROOT.'/tests/router.php'],[0=>['pipe','r'],1=>['file',ROOT.'/storage/test-server.log','a'],2=>['file',ROOT.'/storage/test-server.log','a']],$pipes,ROOT,$environment);
    $ready=false;for($i=0;$i<30;$i++){usleep(100000);$socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,.1);if($socket){fclose($socket);$ready=true;break;}}check($ready,'isolated test server started');
    [$code,$html]=request('/');check($code===200 && str_contains($html,'Continue with Google'),'root renders sign-in');
    [$code]=request('/dashboard');check($code===302,'unauthenticated dashboard rejected');
    [$code]=request('/users',$publisher);check($code===403,'Publisher cannot view user administration');
    [$code]=request('/wordpress',$publisher,['_token'=>$pubCsrf]);check($code===403,'Publisher cannot mutate WordPress settings');
    [$code]=request('/partial',$publisher,['_token'=>'incorrect']);check($code===419,'CSRF mismatch rejected');
    foreach(['/dashboard','/partial','/scheduled','/history','/users','/wordpress','/security'] as $route){[$code,$html]=request($route,$admin);check($code===200 && !str_contains($html,'needs its database'),$route.' renders for administrator');}
    [$code,$html]=request('/dashboard',$publisher);check($code===200 && !str_contains($html,'href="http://127.0.0.1:'.$port.'/paleco-hub/users"'),'Publisher navigation excludes admin tools');
    $text="Power Service Advisory Final Update 10/23/26 12:00 PM\n\nRestored <script>alert(1)</script>.\nThank you.";
    [$code]=request('/partial',$publisher,['_token'=>$pubCsrf,'body'=>$text,'title'=>'']);check($code===302,'partial draft saved');$a=App\Models\DB::query('SELECT * FROM announcements')->fetch();check($a['body']===$text && $a['title']==='Power Service Advisory Final Update 10/23/26 12:00 PM' && $a['status']==='draft','draft text, line breaks, title and status preserved');
    [$code,$html]=request('/history',$publisher);check(str_contains($html,'&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html,'<script>alert(1)</script>'),'stored announcement HTML escaped');
    [$code]=request('/scheduled',$publisher,['_token'=>$pubCsrf,'date'=>'','title'=>'Unknown date']);check($code===302 && App\Models\DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()==1,'scheduled advisory without date cannot save');
    [$code]=request('/users',$admin,['_token'=>$adminCsrf,'email'=>'new@example.invalid','name'=>'New Publisher','role_id'=>2,'is_active'=>1]);check($code===302 && App\Models\DB::query("SELECT COUNT(*) FROM users WHERE email='new@example.invalid'")->fetchColumn()==1,'administrator can approve account');
    [$code]=request('/auth/google',$publisher,['_token'=>$pubCsrf]);check($code===302,'OAuth start redirects');
    $locations=array_filter(request('/auth/google',$admin,['_token'=>$adminCsrf])[2],fn($h)=>str_starts_with(strtolower($h),'location:'));$location=implode('',$locations);check(str_contains($location,'accounts.google.com') && str_contains($location,'code_challenge=') && str_contains($location,'state='),'Google authorization includes state and PKCE');
    [$code]=request('/auth/google/callback?state=invalid&code=invalid',$publisher);check($code===302,'invalid OAuth state rejected');
    if(in_array('--browser',$argv,true)) {
        $command=['node',ROOT.'/tests/browser.cjs','http://127.0.0.1:'.$port.'/paleco-hub',$admin];
        $browserProcess=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$browserPipes,ROOT);$result=proc_close($browserProcess);check($result===0,'real Chromium browser workflows');
    }
    $selector=bin2hex(random_bytes(16));$rememberToken=bin2hex(random_bytes(32));
    App\Models\DB::query('INSERT INTO remembered_devices(selector,user_id,token_hash,device,expires_at) VALUES (?,2,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))',[$selector,hash('sha256',$rememberToken),'Remembered test device']);
    [$code,$html,$headers]=request('/dashboard','',null,'hub_remember='.$selector.':'.$rememberToken);check($code===200,'remembered device restores an approved account');
    $device=App\Models\DB::query('SELECT * FROM remembered_devices WHERE selector=?',[$selector])->fetch();check($device['token_hash']!==hash('sha256',$rememberToken),'remember token rotates on use');
    $cookies=implode('',$headers);check(str_contains($cookies,'HttpOnly')&&str_contains($cookies,'SameSite=Lax')&&str_contains($cookies,'hub_remember='),'remember cookie has HttpOnly and SameSite protections');
    preg_match('/Set-Cookie: PALECOHUB=([^;]+)/i',$cookies,$restoredSession);if(!empty($restoredSession[1]))$sessions[]=$restoredSession[1];
    [$code]=request('/dashboard','',null,'hub_remember='.$selector.':'.$rememberToken);check($code===302,'replayed remember token rejected');
    check(App\Models\DB::query('SELECT revoked_at FROM remembered_devices WHERE selector=?',[$selector])->fetchColumn()!==null,'remember token family revoked after replay');
    [$logoutSession,$logoutCsrf,$logoutAuth]=seedSession(2,'Logout test');
    [$code]=request('/logout',$logoutSession,['_token'=>$logoutCsrf]);check($code===302 && App\Models\DB::query('SELECT revoked_at FROM login_sessions WHERE id=?',[$logoutAuth])->fetchColumn()!==null,'logout revokes server session');
    App\Models\DB::query('UPDATE login_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$pubAuth]);[$code]=request('/dashboard',$publisher);check($code===302,'revoked session cannot access dashboard');
    App\Models\DB::query('UPDATE login_sessions SET last_seen=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 HOUR) WHERE id=?',[$adminAuth]);[$code]=request('/dashboard',$admin);check($code===302,'idle session expires');
    check((int)App\Models\DB::query('SELECT COUNT(*) FROM publishing_history')->fetchColumn()===0,'no publishing occurred');
    require ROOT.'/tests/publishing.php';
    echo "$passed tests passed. Isolated test data will be removed.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $fixture=ROOT.'/storage/wp-fixture-'.$database.'.json';if(is_file($fixture))unlink($fixture);
    if($pdo){foreach($pdo->query('SELECT image_path FROM announcements WHERE image_path IS NOT NULL UNION SELECT storage_name FROM announcement_attachments')->fetchAll(PDO::FETCH_COLUMN) as $image){if(preg_match('/^[a-f0-9]{48}$/',$image)&&is_file(ROOT.'/storage/uploads/'.$image))unlink(ROOT.'/storage/uploads/'.$image);}}
    foreach($sessions as $sid){$file=rtrim(session_save_path(),'\\/').DIRECTORY_SEPARATOR.'sess_'.$sid;if(is_file($file))unlink($file);}
    // Only the random, explicitly created test database may be dropped.
    if($pdo && preg_match('/^paleco_hub_test_[a-f0-9]{12}$/',$database) && $database!==$originalDatabase)$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
