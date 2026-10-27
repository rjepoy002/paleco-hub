<?php
namespace App\Controllers;
use App\Models\DB;
use App\Services\{Auth,Announcement,WordPress,WordPressClient,WordPressFailure,Publisher,Drafts,PartialPublication,Attachments,History};
final class HubController {
    public function __construct(private readonly Publisher $publisher=new Publisher()) {}
    private ?array $user=null;
    private string $path='/';
    public function run(): void {
        $base=parse_url(url(),PHP_URL_PATH) ?: '';
        $this->path='/'.trim(substr(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),strlen($base)),'/');
        try {
            if ($_SERVER['REQUEST_METHOD']==='POST' && (!is_string($_POST['_token'] ?? null) || !hash_equals($_SESSION['csrf'],$_POST['_token']))) {
                http_response_code(419);
                if($this->path==='/partial/publish') {
                    $this->user=Auth::user();
                    if($this->user && in_array((int)$this->user['role_id'],[1,2],true)) { $_SESSION['flash']='The confirmation expired. Your advisory is preserved below. Choose Publish to review it again.'; $this->partialFailure(); return; }
                }
                $this->render('error',['message'=>'This form expired. Refresh the page and try again.']); return;
            }
            if ($this->path==='/auth/google/callback' && $_SERVER['REQUEST_METHOD']==='GET') { Auth::callback(); }
            if ($this->path==='/auth/google' && $_SERVER['REQUEST_METHOD']==='POST') { Auth::redirect(); }
            // An unconfigured installation must still render its sign-in page.
            if (in_array($this->path,['/','/login'],true) && !isset($_SESSION['auth']) && !isset($_COOKIE['hub_remember'])) { $this->render('login'); return; }
            $this->user=Auth::user();
            if (!$this->user) { go('/login'); }
            if (in_array($this->path,['/','/login'],true)) go('/dashboard');
            if (in_array($this->path,['/users','/wordpress'],true) && $this->user['role_id']!=1) { http_response_code(403); $this->render('error',['message'=>'Administrator access is required.']); return; }
            if($this->path==='/history/clear' && (int)$this->user['role_id']!==1){http_response_code(403);$this->render('error',['message'=>'Only Administrators may clear publishing history.']);return;}
            if($this->path==='/history/delete' && !in_array((int)$this->user['role_id'],[1,2],true)){http_response_code(403);$this->render('error',['message'=>'Publisher access is required.']);return;}
            if (in_array($this->path,['/partial','/partial/title','/partial/prepare','/partial/publish','/scheduled','/edit','/announcement','/publish','/image'],true) && !in_array((int)$this->user['role_id'],[1,2],true)) { http_response_code(403); $this->render('error',['message'=>'Publisher or Administrator access is required.']); return; }
            if ($_SERVER['REQUEST_METHOD']==='POST') { $this->post(); return; }
            if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); return; }
            switch ($this->path) {
                case '/dashboard':
                    $stats=DB::query("SELECT COUNT(*) total, COALESCE(SUM(status IN ('draft','wordpress_draft')),0) drafts, COALESCE(SUM(wordpress_status='publish'),0) published FROM announcements")->fetch();
                    $recent=DB::query('SELECT a.*,u.name author FROM announcements a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 5')->fetchAll();
                    $this->render('dashboard',compact('stats','recent')); break;
                case '/partial': case '/scheduled': $this->render('announcement',['type'=>substr($this->path,1)]); break;
                case '/edit': $record=Publisher::find((int)($_GET['id'] ?? 0)); $this->render('announcement',['type'=>$record['type'],'record'=>$record]); break;
                case '/announcement':
                    $record=Publisher::find((int)($_GET['id'] ?? 0)); $mapping=Publisher::mapping($record['type']); $reviewToken=Publisher::reviewToken($record);
                    $attempts=DB::query('SELECT p.*,u.name actor FROM publishing_attempts p JOIN users u ON u.id=p.user_id WHERE p.announcement_id=? ORDER BY p.id DESC LIMIT 50',[$record['id']])->fetchAll();
                    $this->render('review',compact('record','mapping','reviewToken','attempts')); break;
                case '/history':
                    $rows=DB::query('SELECT a.*,u.name author FROM announcements a JOIN users u ON u.id=a.user_id WHERE a.history_hidden_at IS NULL ORDER BY a.id DESC LIMIT 100')->fetchAll();
                    $active=DB::query("SELECT a.*,u.name author FROM announcements a JOIN users u ON u.id=a.user_id WHERE a.history_hidden_at IS NOT NULL AND (a.status IN ('draft','wordpress_draft','publishing') OR a.uncertain_stage IS NOT NULL OR (a.wordpress_post_id IS NOT NULL AND COALESCE(a.synced_revision,0)<>a.revision)) ORDER BY a.id DESC LIMIT 100")->fetchAll();
                    $history=DB::query('SELECT h.*,a.title,u.name author FROM publishing_history h JOIN announcements a ON a.id=h.announcement_id JOIN users u ON u.id=h.user_id WHERE h.deleted_at IS NULL ORDER BY h.id DESC LIMIT 100')->fetchAll();
                    $historySnapshot=History::snapshot();$this->render('history',compact('rows','active','history','historySnapshot'));break;
                case '/users': $rows=DB::query('SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.name')->fetchAll(); $this->render('users',compact('rows')); break;
                case '/wordpress': $connection=DB::query('SELECT id,website_url,rest_url,username,connection_status,tested_at FROM wordpress_connections WHERE id=1')->fetch() ?: ['website_url'=>'https://paleco.net/paleco','rest_url'=>'https://paleco.net/paleco/wp-json/wp/v2','username'=>'','connection_status'=>'Not configured','tested_at'=>null]; $mappings=DB::query('SELECT * FROM category_mappings ORDER BY module')->fetchAll(); $this->render('wordpress',compact('connection','mappings')); break;
                case '/security': $sessions=DB::query('SELECT * FROM login_sessions WHERE user_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY last_seen DESC',[$this->user['id']])->fetchAll(); $devices=DB::query('SELECT selector,device,created_at,expires_at FROM remembered_devices WHERE user_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$this->user['id']])->fetchAll(); $this->render('security',compact('sessions','devices')); break;
                case '/image': $this->image(); break;
                default: http_response_code(404); $this->render('error',['message'=>'The requested page could not be found.']);
            }
        } catch (\Throwable $ex) {
            if (DBReady::isValidation($ex)) { $_SESSION['flash']=$ex->getMessage(); } else { error_log('PALECO Hub failure: '.get_class($ex).' code '.$ex->getCode()); $_SESSION['flash']='The request could not be completed. Check application configuration or contact your administrator.'; }
            if(in_array($this->path,['/partial/title','/partial/prepare'],true)) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(['error'=>$_SESSION['flash']]); unset($_SESSION['flash']); return; }
            if($this->path==='/partial/publish' && $this->user) {
                http_response_code(422);
                $this->partialFailure(); return;
            }
            if ($this->path==='/auth/google/callback' || $this->path==='/auth/google') { header('Location: '.local_url('/login')); exit; }
            if ($_SERVER['REQUEST_METHOD']==='POST') {
                if(in_array($this->path,['/history/delete','/history/clear'],true))go('/history');
                if ($this->path==='/publish') go('/announcement?id='.(int)($_POST['id'] ?? 0));
                if (in_array($this->path,['/partial','/scheduled'],true) && !empty($_POST['id'])) go('/edit?id='.(int)$_POST['id']);
                go(in_array($this->path,['/partial','/scheduled','/users','/wordpress','/security'],true)?$this->path:'/login');
            }
            http_response_code(503); $this->render('error',['message'=>'PALECO Hub needs its database connection and setup completed. See README.md for installation instructions.']);
        }
    }
    private function post(): void {
        $id=(int)$this->user['id'];
        switch ($this->path) {
            case '/logout': Auth::logout($id); go('/login');
            case '/history/delete':
                History::remove($id,(string)($_POST['kind'] ?? ''),(int)($_POST['id'] ?? 0),($_POST['confirmed'] ?? '')==='1');
                $_SESSION['flash']='Local history record deleted. Drafts, files, synchronization IDs and WordPress content were preserved.';go('/history');
            case '/history/clear':
                $count=History::clear($id,(string)($_POST['snapshot_token'] ?? ''),($_POST['confirmed'] ?? '')==='1');
                $_SESSION['flash']=$count.' local history records cleared. Active drafts and WordPress content were preserved.';go('/history');
            case '/partial/title':
                $body=$_POST['body'] ?? '';
                if(!is_string($body) || mb_strlen($body)>100000) throw new \RuntimeException('Enter advisory text up to 100,000 characters.');
                header('Content-Type: application/json'); echo json_encode(Announcement::titleDetails($body),JSON_THROW_ON_ERROR); return;
            case '/partial/prepare':
                header('Content-Type: application/json'); echo json_encode(PartialPublication::prepare($id,$_POST),JSON_THROW_ON_ERROR); return;
            case '/partial/publish':
                $record=PartialPublication::publish($this->publisher,$id,$_POST);
                $_SESSION['flash']='Announcement published to WordPress. Use View on WordPress below to open it.';
                go('/announcement?id='.$record['id']);
            case '/partial': case '/scheduled':
                $savedId=Drafts::save(substr($this->path,1),$id,$_POST,$_FILES['posters'] ?? $_FILES['poster'] ?? null);
                if($this->path==='/scheduled' && ($_POST['next'] ?? '')==='wordpress_draft'){
                    // If a remote upload fails, keep the already-saved attachment identities for retry.
                    $_POST['id']=$savedId;
                    $saved=Publisher::find($savedId);$this->publisher->send($savedId,$id,'draft',(int)$saved['revision'],Publisher::reviewToken($saved),false);
                    $_SESSION['flash']='Saved as a WordPress Draft. No public publication was requested.';go('/announcement?id='.$savedId);
                }
                $_SESSION['flash']='Draft saved locally. Nothing was sent to WordPress.';
                go(($_POST['next'] ?? '')==='review'?'/announcement?id='.$savedId:'/history');
            case '/publish':
                $record=$this->publisher->send((int)($_POST['id'] ?? 0),$id,(string)($_POST['action'] ?? ''),(int)($_POST['revision'] ?? 0),(string)($_POST['review_token'] ?? ''),($_POST['confirmed'] ?? '')==='1');
                $_SESSION['flash']='WordPress result: '.Publisher::status($record).'. The saved remote post ID is reused for future updates.';
                go('/announcement?id='.$record['id']);
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
                foreach ($mappings as $m) $resolved[$m['module']]=(new WordPressClient($c))->category($m);
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
            try { DB::query("INSERT INTO wordpress_connections (id,website_url,rest_url,username,password_encrypted) VALUES (1,?,?,?,?) ON DUPLICATE KEY UPDATE website_url=VALUES(website_url),rest_url=VALUES(rest_url),username=VALUES(username),password_encrypted=VALUES(password_encrypted),connection_status='Untested',tested_at=NULL",[$website,$rest,$username,$encrypted]); foreach(['partial'=>$partial,'scheduled'=>$scheduled] as $module=>$name) DB::query('UPDATE category_mappings SET category_id=IF(category_name=? AND ?,category_id,NULL),category_name=? WHERE module=?',[$name,$old && $old['rest_url']===$rest ? 1:0,$name,$module]); DB::audit((int)$this->user['id'],'wordpress.settings_updated'); $db->commit(); } catch (\Throwable $ex) { $db->rollBack(); throw $ex; }
            $_SESSION['flash']='Settings saved securely. Test the connection to resolve category IDs.';
        }
        go('/wordpress');
    }
    private function image(): void {
        if(isset($_GET['attachment'])){
            $poster=DB::query('SELECT storage_name,mime FROM announcement_attachments WHERE id=? AND removed_at IS NULL',[(int)$_GET['attachment']])->fetch();
            if(!$poster || !is_file(\App\Services\Uploads::path($poster['storage_name']))){http_response_code(404);return;}
            header('Content-Type: '.$poster['mime']);header('Content-Disposition: inline');readfile(\App\Services\Uploads::path($poster['storage_name']));return;
        }
        $a=DB::query('SELECT image_path,image_mime FROM announcements WHERE id=?',[(int)($_GET['id'] ?? 0)])->fetch();
        if (!$a || !$a['image_path'] || !preg_match('/^[a-f0-9]{48}$/',$a['image_path']) || !is_file(ROOT.'/storage/uploads/'.$a['image_path'])) { http_response_code(404); return; }
        header('Content-Type: '.$a['image_mime']); header('Content-Disposition: inline'); readfile(ROOT.'/storage/uploads/'.$a['image_path']);
    }
    private function render(string $view,array $data=[]): void { extract($data,EXTR_SKIP); $user=$this->user; $path=$this->path; ob_start(); require ROOT.'/app/Views/'.$view.'.php'; $content=ob_get_clean(); require ROOT.'/app/Views/layout.php'; }
    private function partialFailure(): void {
        $record=['id'=>is_scalar($_POST['id'] ?? null)?(int)$_POST['id']:0,'revision'=>is_scalar($_POST['revision'] ?? null)?(int)$_POST['revision']:0,'title'=>is_string($_POST['title'] ?? null)?mb_scrub($_POST['title'],'UTF-8'):'','body'=>is_string($_POST['body'] ?? null)?mb_scrub($_POST['body'],'UTF-8'):''];
        $operation=is_string($_POST['operation'] ?? null)&&preg_match('/^[a-f0-9]{64}$/D',$_POST['operation'])?$_POST['operation']:bin2hex(random_bytes(32));
        $this->render('announcement',['type'=>'partial','record'=>$record,'operation'=>$operation]);
    }
}
final class DBReady { public static function isValidation(\Throwable $ex): bool { return get_class($ex)===\RuntimeException::class || $ex instanceof WordPressFailure; } }
