<?php
namespace App\Controllers;
use App\Models\DB;
use App\Services\{Auth,Announcement,WordPress,UnconfiguredRecognition};
final class HubController {
    private ?array $user=null;
    private string $path='/';
    public function run(): void {
        $base=parse_url(url(),PHP_URL_PATH) ?: '';
        $this->path='/'.trim(substr(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),strlen($base)),'/');
        try {
            if ($_SERVER['REQUEST_METHOD']==='POST' && (!is_string($_POST['_token'] ?? null) || !hash_equals($_SESSION['csrf'],$_POST['_token']))) { http_response_code(419); $this->render('error',['message'=>'This form expired. Refresh the page and try again.']); return; }
            if ($this->path==='/auth/google/callback' && $_SERVER['REQUEST_METHOD']==='GET') { Auth::callback(); }
            if ($this->path==='/auth/google' && $_SERVER['REQUEST_METHOD']==='POST') { Auth::redirect(); }
            // An unconfigured installation must still render its sign-in page.
            if (in_array($this->path,['/','/login'],true) && !isset($_SESSION['auth']) && !isset($_COOKIE['hub_remember'])) { $this->render('login'); return; }
            $this->user=Auth::user();
            if (!$this->user) { go('/login'); }
            if (in_array($this->path,['/','/login'],true)) go('/dashboard');
            if (in_array($this->path,['/users','/wordpress'],true) && $this->user['role_id']!=1) { http_response_code(403); $this->render('error',['message'=>'Administrator access is required.']); return; }
            if ($_SERVER['REQUEST_METHOD']==='POST') { $this->post(); return; }
            if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); return; }
            switch ($this->path) {
                case '/dashboard':
                    $stats=DB::query("SELECT COUNT(*) total, COALESCE(SUM(status='draft'),0) drafts, COALESCE(SUM(status='published'),0) published FROM announcements")->fetch();
                    $recent=DB::query('SELECT a.*,u.name author FROM announcements a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 5')->fetchAll();
                    $this->render('dashboard',compact('stats','recent')); break;
                case '/partial': case '/scheduled': $this->render('announcement',['type'=>substr($this->path,1)]); break;
                case '/history': $rows=DB::query('SELECT a.*,u.name author FROM announcements a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll(); $history=DB::query('SELECT h.*,a.title,u.name author FROM publishing_history h JOIN announcements a ON a.id=h.announcement_id JOIN users u ON u.id=h.user_id ORDER BY h.id DESC LIMIT 100')->fetchAll(); $this->render('history',compact('rows','history')); break;
                case '/users': $rows=DB::query('SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.name')->fetchAll(); $this->render('users',compact('rows')); break;
                case '/wordpress': $connection=DB::query('SELECT id,website_url,rest_url,username,connection_status,tested_at FROM wordpress_connections WHERE id=1')->fetch() ?: ['website_url'=>'https://paleco.net/paleco','rest_url'=>'https://paleco.net/paleco/wp-json/wp/v2','username'=>'','connection_status'=>'Not configured','tested_at'=>null]; $mappings=DB::query('SELECT * FROM category_mappings ORDER BY module')->fetchAll(); $this->render('wordpress',compact('connection','mappings')); break;
                case '/security': $sessions=DB::query('SELECT * FROM login_sessions WHERE user_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY last_seen DESC',[$this->user['id']])->fetchAll(); $devices=DB::query('SELECT selector,device,created_at,expires_at FROM remembered_devices WHERE user_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$this->user['id']])->fetchAll(); $this->render('security',compact('sessions','devices')); break;
                case '/image': $this->image(); break;
                default: http_response_code(404); $this->render('error',['message'=>'The requested page could not be found.']);
            }
        } catch (\Throwable $ex) {
            if (DBReady::isValidation($ex)) { $_SESSION['flash']=$ex->getMessage(); } else { error_log('PALECO Hub failure: '.get_class($ex).' code '.$ex->getCode()); $_SESSION['flash']='The request could not be completed. Check application configuration or contact your administrator.'; }
            if ($this->path==='/auth/google/callback' || $this->path==='/auth/google') go('/login');
            if ($_SERVER['REQUEST_METHOD']==='POST') go(in_array($this->path,['/partial','/scheduled','/users','/wordpress','/security'],true)?$this->path:'/login');
            http_response_code(503); $this->render('error',['message'=>'PALECO Hub needs its database connection and setup completed. See README.md for installation instructions.']);
        }
    }
    private function post(): void {
        $id=(int)$this->user['id'];
        switch ($this->path) {
            case '/logout': Auth::logout($id); go('/login');
            case '/partial': case '/scheduled':
                $type=substr($this->path,1); $body=(string)($_POST['body'] ?? ''); $title=(string)($_POST['title'] ?? ''); $date=null; $image=null; $mime=null;
                if ($type==='partial') { if (!trim($body) || mb_strlen($body)>100000) throw new \RuntimeException('Enter an announcement up to 100,000 characters.'); if (!trim($title)) $title=Announcement::title($body); }
                else {
                    $date=(string)($_POST['date'] ?? ''); $generated=Announcement::dateTitle($date); if (!trim($title)) $title=$generated;
                    if (!isset($_POST['date_confirmed'])) throw new \RuntimeException('Confirm the interruption date shown on the poster.');
                    $f=$_FILES['poster'] ?? null;
                    if (!$f || $f['error']!==UPLOAD_ERR_OK || $f['size']>8*1024*1024) throw new \RuntimeException('Select a JPG, PNG, or WebP poster up to 8 MB.');
                    $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                    $dimensions=@getimagesize($f['tmp_name']);
                    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !$dimensions || $dimensions[0]*$dimensions[1]>40000000) throw new \RuntimeException('Use a valid JPG, PNG, or WebP image up to 40 megapixels.');
                    $dates=(new UnconfiguredRecognition())->dates($f['tmp_name']);
                    if (count($dates)>1 && (!isset($_POST['date_confirmed']) || !in_array($date,$dates,true))) throw new \RuntimeException('Confirm one of the detected interruption dates.');
                    $image=bin2hex(random_bytes(24)); if (!is_dir(ROOT.'/storage/uploads')) mkdir(ROOT.'/storage/uploads',0700,true);
                    if (!move_uploaded_file($f['tmp_name'],ROOT.'/storage/uploads/'.$image)) throw new \RuntimeException('The poster could not be stored.');
                }
                if (!trim($title) || mb_strlen($title)>255) { if ($image) unlink(ROOT.'/storage/uploads/'.$image); throw new \RuntimeException('Enter a title up to 255 characters.'); }
                $db=DB::connection(); $db->beginTransaction();
                try { DB::query('INSERT INTO announcements (user_id,type,title,body,image_path,image_mime,interruption_date) VALUES (?,?,?,?,?,?,?)',[$id,$type,$title,$body,$image,$mime,$date]); DB::audit($id,'announcement.draft_saved'); $db->commit(); }
                catch (\Throwable $ex) { $db->rollBack(); if ($image) unlink(ROOT.'/storage/uploads/'.$image); throw $ex; }
                $_SESSION['flash']='Draft saved. Publishing is disabled during Phase 1.'; go('/history');
            case '/users': $this->saveUser(); break;
            case '/wordpress': $this->wordpress(); break;
            case '/security':
                if (($_POST['kind'] ?? '')==='session') DB::query('UPDATE login_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?',[(string)($_POST['id'] ?? ''),$id]);
                elseif (($_POST['kind'] ?? '')==='device') DB::query('UPDATE remembered_devices SET revoked_at=UTC_TIMESTAMP() WHERE selector=? AND user_id=?',[(string)($_POST['id'] ?? ''),$id]);
                elseif (($_POST['kind'] ?? '')==='all') { DB::query('UPDATE login_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=?',[$id]); DB::query('UPDATE remembered_devices SET revoked_at=UTC_TIMESTAMP() WHERE user_id=?',[$id]); Auth::cookie('',time()-3600); }
                else throw new \RuntimeException('Invalid revocation request.');
                DB::audit($id,'security.revoked'); $_SESSION['flash']='Access revoked.'; go('/security');
            default: http_response_code(404); $this->render('error',['message'=>'The requested action could not be found.']);
        }
    }
    private function saveUser(): never {
        $email=strtolower(trim((string)($_POST['email'] ?? ''))); $name=trim((string)($_POST['name'] ?? '')); $role=(int)($_POST['role_id'] ?? 2); $active=isset($_POST['is_active'])?1:0; $target=(int)($_POST['id'] ?? 0);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || !$name || mb_strlen($name)>190 || !in_array($role,[1,2],true)) throw new \RuntimeException('Enter a valid name, email, and role.');
        if ($target===$this->user['id']) throw new \RuntimeException('Ask another administrator to change your own access.');
        $db=DB::connection(); $db->beginTransaction();
        try {
            // Serialize administrators so concurrent requests cannot disable the last administrator.
            $admins=DB::query('SELECT id FROM users WHERE role_id=1 AND is_active=1 ORDER BY id FOR UPDATE')->fetchAll();
            if ($target) {
                $old=DB::query('SELECT * FROM users WHERE id=? FOR UPDATE',[$target])->fetch();
                if (!$old) throw new \RuntimeException('User not found.');
                if ($old['role_id']==1 && $old['is_active'] && count($admins)<=1 && ($role!==1 || !$active)) throw new \RuntimeException('Keep at least one active administrator.');
                if ($email!==$old['email']) throw new \RuntimeException('Approved email identities cannot be changed. Create a new approved account instead.');
                DB::query('UPDATE users SET name=?,role_id=?,is_active=? WHERE id=?',[$name,$role,$active,$target]);
                DB::query('UPDATE login_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=?',[$target]); DB::query('UPDATE remembered_devices SET revoked_at=UTC_TIMESTAMP() WHERE user_id=?',[$target]);
            } else DB::query('INSERT INTO users (email,name,role_id,is_active) VALUES (?,?,?,?)',[$email,$name,$role,$active]);
            DB::audit((int)$this->user['id'],'users.access_updated'); $db->commit();
        } catch (\Throwable $ex) { $db->rollBack(); if ($ex instanceof \PDOException && $ex->getCode()==='23000') throw new \RuntimeException('That email address is already approved.'); throw $ex; }
        $_SESSION['flash']='Approved account saved. Existing access was revoked for updated accounts.'; go('/users');
    }
    private function wordpress(): never {
        if (($_POST['action'] ?? '')==='test') {
            $c=DB::query('SELECT * FROM wordpress_connections WHERE id=1')->fetch(); if (!$c) throw new \RuntimeException('Save connection settings before testing.');
            try {
                $me=WordPress::get($c,'/users/me?context=edit'); if (empty($me['id']) || empty($me['capabilities']['edit_posts'])) throw new \RuntimeException('The WordPress account needs permission to edit posts.');
                $mappings=DB::query('SELECT * FROM category_mappings')->fetchAll(); $resolved=[];
                foreach ($mappings as $m) { $match=null; for($page=1;$page<=10;$page++) { $categories=WordPress::get($c,'/categories?per_page=100&page='.$page.'&search='.rawurlencode($m['category_name'])); foreach($categories as $category) { if (($category['name'] ?? '')===$m['category_name'] || strcasecmp($category['slug'] ?? '',$m['category_name'])===0) $match=(int)$category['id']; } if (count($categories)<100) break; } if (!$match) throw new \RuntimeException('A mapped category was not found. Check the exact category names.'); $resolved[$m['module']]=$match; }
                $db=DB::connection(); $db->beginTransaction(); foreach($resolved as $module=>$categoryId) DB::query('UPDATE category_mappings SET category_id=? WHERE module=?',[$categoryId,$module]); DB::query("UPDATE wordpress_connections SET connection_status='Connected',tested_at=UTC_TIMESTAMP() WHERE id=1"); DB::audit((int)$this->user['id'],'wordpress.connection_tested'); $db->commit(); $_SESSION['flash']='Connection verified and actual category IDs retrieved. No content was published.';
            } catch (\Throwable $ex) { if (DB::connection()->inTransaction()) DB::connection()->rollBack(); DB::query("UPDATE wordpress_connections SET connection_status='Failed',tested_at=UTC_TIMESTAMP() WHERE id=1"); throw $ex; }
        } else {
            $website=trim((string)($_POST['website_url'] ?? '')); $rest=rtrim(trim((string)($_POST['rest_url'] ?? '')),'/'); WordPress::validateUrl($website); WordPress::validateUrl($rest);
            if (parse_url($website,PHP_URL_HOST)!==parse_url($rest,PHP_URL_HOST)) throw new \RuntimeException('Website and REST API must use the same hostname.');
            $username=trim((string)($_POST['username'] ?? '')); if (!$username || strlen($username)>190 || str_contains($username,':')) throw new \RuntimeException('Enter a valid WordPress username.');
            $old=DB::query('SELECT * FROM wordpress_connections WHERE id=1')->fetch(); $password=(string)($_POST['application_password'] ?? '');
            if (!$password && (!$old || $old['rest_url']!==$rest || $old['username']!==$username)) throw new \RuntimeException('Enter an Application Password for the new connection.');
            $encrypted=$password!==''?WordPress::encrypt($password):$old['password_encrypted'];
            $partial=trim((string)($_POST['category_partial'] ?? '')); $scheduled=trim((string)($_POST['category_scheduled'] ?? ''));
            if (!$partial || !$scheduled || strlen($partial)>100 || strlen($scheduled)>100) throw new \RuntimeException('Enter both category names (up to 100 characters).');
            $db=DB::connection(); $db->beginTransaction();
            try { DB::query("INSERT INTO wordpress_connections (id,website_url,rest_url,username,password_encrypted) VALUES (1,?,?,?,?) ON DUPLICATE KEY UPDATE website_url=VALUES(website_url),rest_url=VALUES(rest_url),username=VALUES(username),password_encrypted=VALUES(password_encrypted),connection_status='Untested',tested_at=NULL",[$website,$rest,$username,$encrypted]); foreach(['partial'=>$partial,'scheduled'=>$scheduled] as $module=>$name) DB::query('UPDATE category_mappings SET category_name=?,category_id=NULL WHERE module=?',[$name,$module]); DB::audit((int)$this->user['id'],'wordpress.settings_updated'); $db->commit(); } catch (\Throwable $ex) { $db->rollBack(); throw $ex; }
            $_SESSION['flash']='Settings saved securely. Test the connection to resolve category IDs.';
        }
        go('/wordpress');
    }
    private function image(): void {
        $a=DB::query('SELECT image_path,image_mime FROM announcements WHERE id=?',[(int)($_GET['id'] ?? 0)])->fetch();
        if (!$a || !$a['image_path'] || !preg_match('/^[a-f0-9]{48}$/',$a['image_path']) || !is_file(ROOT.'/storage/uploads/'.$a['image_path'])) { http_response_code(404); return; }
        header('Content-Type: '.$a['image_mime']); header('Content-Disposition: inline'); readfile(ROOT.'/storage/uploads/'.$a['image_path']);
    }
    private function render(string $view,array $data=[]): void { extract($data,EXTR_SKIP); $user=$this->user; $path=$this->path; ob_start(); require ROOT.'/app/Views/'.$view.'.php'; $content=ob_get_clean(); require ROOT.'/app/Views/layout.php'; }
}
final class DBReady { public static function isValidation(\Throwable $ex): bool { return get_class($ex)===\RuntimeException::class; } }
