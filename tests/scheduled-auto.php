<?php
use App\Models\DB;
use App\Services\Publisher;
// HTTP multipart tests exercise the real upload handler with fake WordPress/OCR only.
function scheduledAutoRequest(array $paths,array $input): array {
    global $port,$httpAdmin,$httpAdminCsrf;
    $fields=array_merge(['_token'=>$httpAdminCsrf,'id'=>'0','revision'=>'0','date'=>'2026-10-25','date_source'=>'ocr','title'=>'Scheduled Power Interruption [October 25, 2026]','confirmed'=>'1'],$input);
    foreach($paths as $i=>$path)$fields['posters['.$i.']']=new CURLFile($path,'image/png','same-poster.png');
    $ch=curl_init('http://127.0.0.1:'.$port.'/paleco-hub/scheduled/publish');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_COOKIE=>'PALECOHUB='.$httpAdmin,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$code,json_decode($body,true)];
}
$setMode=function($mode)use($fixture){$s=json_decode(file_get_contents($fixture),true);$s['mode']=$mode;file_put_contents($fixture,json_encode($s));};
$postCount=function()use($fixture){$s=json_decode(file_get_contents($fixture),true);return count(array_filter($s['calls'],fn($c)=>$c['method']==='POST'&&$c['path']==='/posts'));};
$map=[];foreach($paths as $path)$map[hash_file('sha256',$path)]='Interruption Date: October 25, 2026 Sunday';file_put_contents($visionFile,json_encode(['text'=>$map]));DB::query('DELETE FROM ocr_cache');
[$code]=request('/scheduled/publish',$httpAdmin,['_token'=>'invalid']);check($code===419,'scheduled auto endpoint enforces CSRF');
[$code]=request('/scheduled/publish',$httpViewer,['_token'=>$httpViewerCsrf]);check($code===403,'scheduled auto endpoint enforces publisher role');
[$code]=scheduledAutoRequest([$paths[0]],['operation'=>bin2hex(random_bytes(32)),'confirmed'=>'0']);check($code===422,'scheduled auto endpoint requires explicit confirmation');
$setMode('ok');$operation=bin2hex(random_bytes(32));$count=$postCount();
[$code,$result]=scheduledAutoRequest(array_slice($paths,0,2),['operation'=>$operation]);
check($code===200&&$result['wordpress_status']==='publish'&&$result['history_finalized']===true,'single confirmed scheduled request publishes and finalizes history');
check($result['wordpress_post_url']==='https://wordpress.example.invalid/?p='.$result['wordpress_post_id'],'scheduled success exposes the verified WordPress permalink');
$id=$result['wordpress_post_id'];$remote=json_decode(file_get_contents($fixture),true)['posts'][$id];
check($remote['title']['raw']==='Scheduled Power Interruption [October 25, 2026]'&&$remote['categories']===[9],'scheduled auto publication preserves generated title and INTERRUPTIONS category');
check($remote['featured_media']>0&&substr_count($remote['content']['raw'],'<img')===1&&!str_contains($remote['content']['raw'],'wp-image-'.$remote['featured_media'].'"'),'two-poster auto publication does not duplicate featured poster');
$tagState=json_decode(file_get_contents($fixture),true)['tags'];check(count(array_filter($remote['tags'],fn($id)=>($tagState[$id]['name'] ?? '')==='2026'))===1,'scheduled auto publication assigns interruption year tag');
[$code,$replay]=scheduledAutoRequest(array_slice($paths,0,2),['operation'=>$operation]);check($code===200&&$replay['wordpress_post_id']===$id&&$postCount()===$count+1,'repeated confirmed operation does not duplicate scheduled post');
[$code,$next]=scheduledAutoRequest([$paths[0]],['operation'=>$result['next_operation']]);check($code===200&&$next['wordpress_post_id']!==$id,'new scheduled operation independently publishes same filename/image');
[$code,$three]=scheduledAutoRequest(array_slice($paths,0,3),['operation'=>bin2hex(random_bytes(32))]);
$threeState=json_decode(file_get_contents($fixture),true);$threePost=$threeState['posts'][$three['wordpress_post_id']];
$threeRecord=DB::query('SELECT * FROM announcements WHERE wordpress_post_id=?',[$three['wordpress_post_id']])->fetch();$threeAttachments=App\Services\Attachments::all((int)$threeRecord['id']);
check($code===200&&count($threeAttachments)===3&&$threePost['featured_media']===(int)$threeAttachments[0]['wordpress_media_id'],'three-poster publication selects the first poster as featured');
$body=$threePost['content']['raw'];
check(substr_count($body,'<img')===2&&strpos($body,$threeAttachments[0]['wordpress_media_url'])===false&&strpos($body,$threeAttachments[1]['wordpress_media_url'])<strpos($body,$threeAttachments[2]['wordpress_media_url']),'three posters render once each in theme-plus-body order');
check(!str_contains(strtolower($body),'facebook')&&!str_contains($body,'[elementor-template'),'Hub content adds no sharing button or duplicate shortcode');
check($threeRecord['wordpress_post_url']===$three['wordpress_post_url']&&(bool)DB::query("SELECT 1 FROM publishing_history WHERE announcement_id=? AND wordpress_post_id=? AND status='published'",[$threeRecord['id'],$three['wordpress_post_id']])->fetchColumn(),'reset-ready publication preserves stored permalink and history');
$setMode('post-timeout');$operation=bin2hex(random_bytes(32));[$code]=scheduledAutoRequest([$paths[0]],['operation'=>$operation]);check($code===422,'scheduled post timeout does not report reset-ready success');$count=$postCount();[$code,$retry]=scheduledAutoRequest([$paths[0]],['operation'=>$operation]);check($code===200&&$postCount()===$count,'scheduled timeout retry reconciles without new post');
$setMode('ok');$operation=bin2hex(random_bytes(32));
DB::query("CREATE TRIGGER fail_scheduled_history BEFORE INSERT ON publishing_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected history failure'");
try { [$code,$failed]=scheduledAutoRequest([$paths[0]],['operation'=>$operation]);check($code===422,'scheduled local history failure cannot report complete success');check(str_contains($failed['error'],'WordPress has published'),'history failure distinguishes remote publication from incomplete local finalization'); }
finally { DB::query('DROP TRIGGER fail_scheduled_history'); }
$key='scheduled_publish_'.hash('sha256','1:'.$operation);$entry=json_decode(DB::query('SELECT setting_value FROM application_settings WHERE setting_key=?',[$key])->fetchColumn(),true);$pending=Publisher::find($entry['id']);
check($pending['wordpress_post_id']&&$pending['uncertain_stage']==='attachment','scheduled history failure retains accepted WordPress ID and reconciliation payload');
$count=$postCount();[$code,$retry]=scheduledAutoRequest([$paths[0]],['operation'=>$operation]);check($code===200&&$retry['wordpress_post_id']===(int)$pending['wordpress_post_id']&&$postCount()===$count,'scheduled history reconciliation preserves remote ID without another publication');
$setMode('auth');$operation=bin2hex(random_bytes(32));[$code]=scheduledAutoRequest([$paths[0]],['operation'=>$operation]);check($code===422,'scheduled WordPress authentication failure preserves retryable operation');
$setMode('ok');[$code]=scheduledAutoRequest([$paths[0]],['operation'=>$operation,'title'=>'Changed retry']);check($code===422,'changed retry cannot silently overwrite an outstanding scheduled submission');
$plugin=proc_open([PHP_BINARY,ROOT.'/tests/wordpress-scheduled-sharing.php'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,ROOT);check(proc_close($plugin)===0,'WordPress sharing helper preserves existing widget rendering without duplication');
if(in_array('--browser',$argv,true)){$p=proc_open(['node',ROOT.'/tests/scheduled-auto-browser.cjs','http://127.0.0.1:'.$port.'/paleco-hub',$httpAdmin,$visionFile,$fixture],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,ROOT);check(proc_close($p)===0,'scheduled confirmed auto-publication/reset browser checks');}
$mock->state=json_decode(file_get_contents($fixture),true);
