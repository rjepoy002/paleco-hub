<?php
use App\Models\DB;
use App\Services\{Attachments,Publisher,History,WordPress,WordPressFailure};
function uploadPosters(array $paths,string $title,string $next='',array $extra=[]): array {
    global $port,$httpAdmin,$httpAdminCsrf;
    $fields=array_merge(['_token'=>$httpAdminCsrf,'title'=>$title,'date'=>'2026-10-30','date_confirmed'=>'1','next'=>$next],$extra);
    foreach($paths as $index=>$path)$fields['posters['.$index.']']=new CURLFile($path,'application/octet-stream','poster-'.$index.'.png');
    $headers=[];$ch=curl_init('http://127.0.0.1:'.$port.'/paleco-hub/scheduled');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_COOKIE=>'PALECOHUB='.$httpAdmin,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$headers[]=$line;return strlen($line);}]);$body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$code,$body,$headers];
}
$testFiles=[];
try{
    for($n=0;$n<5;$n++){$path=ROOT.'/storage/poster-fixture-'.$n.'.png';copy(ROOT.'/public/assets/paleco-logo.png',$path);file_put_contents($path,"\nfixture $n",FILE_APPEND);$testFiles[]=$path;}
    $state=json_decode(file_get_contents($fixture),true);$state['mode']='ok';file_put_contents($fixture,json_encode($state));
    $callsBefore=count($state['calls']);$savedIds=[];
    foreach([1,2,5] as $count){
        $title='Multi upload '.$count;[$code]=uploadPosters(array_slice($testFiles,0,$count),$title);check($code===302,'upload '.$count.' poster(s) saves local draft');
        $row=DB::query('SELECT * FROM announcements WHERE title=? ORDER BY id DESC LIMIT 1',[$title])->fetch();$savedIds[$count]=(int)$row['id'];$items=Attachments::all((int)$row['id']);
        check(count($items)===$count&&$row['status']==='draft','normalized attachments stored for '.$count.' poster(s)');
        check(array_column($items,'content_hash')===array_map(fn($p)=>hash_file('sha256',$p),array_slice($testFiles,0,$count)),'upload order retained for '.$count.' poster(s)');
    }
    check(count(json_decode(file_get_contents($fixture),true)['calls'])===$callsBefore,'multi-image local drafts make no WordPress calls');
    $recognizer=new class implements App\Services\RecognitionEngine { public function dates(string $path): array{return ['2026-10-30','2026-10-31'];} };
    $dateInput=['id'=>$savedIds[1],'revision'=>1,'date'=>'2026-10-30','date_confirmed'=>'1','title'=>'Multi upload 1'];
    rejects(fn()=>App\Services\ScheduledDrafts::save(1,$dateInput,null,$recognizer),'conflicting recognized poster dates require extra confirmation');
    App\Services\ScheduledDrafts::save(1,array_merge($dateInput,['date_conflict_confirmed'=>'1']),null,$recognizer);check(Publisher::find($savedIds[1])['interruption_date']==='2026-10-30','reviewed conflict keeps the explicitly confirmed interruption date');
    $invalid=ROOT.'/storage/invalid-upload.png';file_put_contents($invalid,'<?php malicious executable ?>');$testFiles[]=$invalid;
    $before=(int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn();uploadPosters([$testFiles[0],$invalid],'Invalid batch');check((int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$before,'invalid MIME/signature rejects whole upload batch');
    $large=ROOT.'/storage/oversized-upload.png';$largeHandle=fopen($large,'wb');fwrite($largeHandle,file_get_contents($testFiles[0]));ftruncate($largeHandle,8*1024*1024+1);fclose($largeHandle);$testFiles[]=$large;
    uploadPosters([$large],'Oversized batch');check((int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$before,'8 MB per-file limit enforced');
    uploadPosters(array_fill(0,11,$testFiles[0]),'Too many posters');check((int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$before,'default maximum ten posters enforced server-side');
    $failureState=json_decode(file_get_contents($fixture),true);$failureState['mode']='media-rejected';file_put_contents($fixture,json_encode($failureState));
    [$code,$body,$headers]=uploadPosters(array_slice($testFiles,0,2),'HTTP retryable posters','wordpress_draft');$retryRow=DB::query("SELECT * FROM announcements WHERE title='HTTP retryable posters' ORDER BY id DESC LIMIT 1")->fetch();
    check($retryRow['status']==='failed'&&str_contains(implode('',$headers),'/edit?id='.$retryRow['id']),'failed WordPress Draft action redirects to saved attachment identities');
    $failureState=json_decode(file_get_contents($fixture),true);$failureState['mode']='ok';file_put_contents($fixture,json_encode($failureState));$rowsBeforeRetry=(int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn();
    uploadPosters([],'HTTP retryable posters','wordpress_draft',['id'=>$retryRow['id'],'revision'=>$retryRow['revision']]);check((int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$rowsBeforeRetry&&Publisher::find((int)$retryRow['id'])['status']==='wordpress_draft','retrying saved posters does not create a second local announcement or post');
    uploadPosters([$testFiles[0],$testFiles[1]],'Multi remote draft','wordpress_draft');$remoteDraft=DB::query("SELECT * FROM announcements WHERE title='Multi remote draft' ORDER BY id DESC LIMIT 1")->fetch();
    check($remoteDraft['status']==='wordpress_draft'&&count(Attachments::all((int)$remoteDraft['id']))===2,'Save as WordPress Draft uploads two posters without publishing');
    $mock->state=json_decode(file_get_contents($fixture),true);$draftPostId=(int)$remoteDraft['wordpress_post_id'];$a=sendMock((int)$remoteDraft['id']);$post=$mock->state['posts'][$draftPostId];$items=Attachments::all((int)$a['id']);
    check((int)$a['wordpress_post_id']===$draftPostId&&$post['status']==='publish','multi-poster WordPress draft publishes by updating same post');
    check(substr_count($post['content']['raw'],'<img')===2&&$post['featured_media']===(int)$items[0]['wordpress_media_id'],'all posters included and first is featured');
    $positions=array_map(fn($p)=>strpos($post['content']['raw'],$p['wordpress_media_url']),$items);check($positions[0]<$positions[1],'WordPress image content preserves selected order');
    check(count(array_filter($items,fn($p)=>($mock->state['media'][$p['wordpress_media_id']]['post'] ?? null)===$draftPostId))===2,'all WordPress media items associated with same post');
    $multiId=$savedIds[5];$mock->state['reject_media_number']=($mock->state['media_upload_calls'] ?? 0)+2;$postCount=writeCount();rejects(fn()=>sendMock($multiId),'second media failure stops post publication');
    $partial=Attachments::all($multiId);check($partial[0]['wordpress_media_id']&&!$partial[1]['wordpress_media_id']&&writeCount()===$postCount,'first accepted media retained before later failure');
    $firstMedia=$partial[0]['wordpress_media_id'];$mediaCount=count($mock->state['media']);unset($mock->state['reject_media_number']);$a=sendMock($multiId);$items=Attachments::all($multiId);
    check($items[0]['wordpress_media_id']===$firstMedia&&count($mock->state['media'])===$mediaCount+4,'retry uploads only remaining posters');
    check(substr_count($mock->state['posts'][$a['wordpress_post_id']]['content']['raw'],'<img')===5,'five-poster publication uses one post');
    $uncertainId=$savedIds[2];$mock->state['timeout_media_number']=($mock->state['media_upload_calls'] ?? 0)+2;rejects(fn()=>sendMock($uncertainId),'accepted second poster timeout leaves durable pending attachment');$mediaCount=count($mock->state['media']);unset($mock->state['timeout_media_number']);$a=sendMock($uncertainId);
    check(count($mock->state['media'])===$mediaCount&&$a['status']==='published','multi-image timeout reconciles without duplicate media');
    $legacy=DB::query('SELECT * FROM announcements WHERE type=\'scheduled\' AND image_path IS NOT NULL ORDER BY id LIMIT 1')->fetch();check(count(Attachments::all((int)$legacy['id']))>=1,'legacy single-image announcement remains compatible');
    $legacyName=bin2hex(random_bytes(24));copy($testFiles[0],App\Services\Uploads::path($legacyName));
    DB::query("INSERT INTO announcements(user_id,type,title,image_path,image_mime,interruption_date,wordpress_media_id,wordpress_media_url) VALUES (1,'scheduled','Pre-migration poster',?,'image/png','2026-10-30',777,'https://wordpress.example.invalid/legacy.png')",[$legacyName]);$legacyId=(int)DB::connection()->lastInsertId();
    (require ROOT.'/database/migrations/003_multi_posters_history.php')();$backfilled=Attachments::all($legacyId);check(count($backfilled)===1&&(int)$backfilled[0]['wordpress_media_id']===777,'migration backfills legacy poster and retains its remote media ID');
    $countPosts=count($mock->state['posts']);$countMedia=count($mock->state['media']);$attemptCount=(int)DB::query('SELECT COUNT(*) FROM publishing_attempts')->fetchColumn();
    rejects(fn()=>History::remove(1,'announcement',$multiId,false),'single history deletion requires confirmation');
    $originalPostId=Publisher::find($multiId)['wordpress_post_id'];History::remove(1,'announcement',$multiId,true);$hidden=Publisher::find($multiId);check($hidden['history_hidden_at']!==null&&$hidden['wordpress_post_id']===$originalPostId,'announcement history entry is soft deleted with remote identity preserved');
    check(count(Attachments::all($multiId))===5&&is_file(App\Services\Uploads::path($items[0]['storage_name'])),'deleting history preserves attachment records and files');
    check(History::remove(1,'announcement',$multiId,true)===0,'repeated history deletion is idempotent');
    $event=DB::query('SELECT * FROM publishing_history WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetch();History::remove(1,'event',(int)$event['id'],true);check(DB::query('SELECT deleted_at FROM publishing_history WHERE id=?',[$event['id']])->fetchColumn()!==null,'single event deletion is soft deleted');
    $otherPublisher=(int)DB::query("SELECT id FROM users WHERE email='new@example.invalid'")->fetchColumn();rejects(fn()=>History::remove($otherPublisher,'announcement',$savedIds[1],true),'publisher cannot delete another user history');
    $snapshot=History::snapshot();rejects(fn()=>History::clear(2,$snapshot['token'],true),'publisher cannot bulk clear history');rejects(fn()=>History::clear(1,$snapshot['token'],false),'bulk clear requires explicit confirmation');
    DB::query("INSERT INTO publishing_history(announcement_id,user_id,status,message) VALUES (?,1,'draft','New event while confirmation open')",[$savedIds[1]]);rejects(fn()=>History::clear(1,$snapshot['token'],true),'changed history count invalidates bulk confirmation');
    $snapshot=History::snapshot();$cleared=History::clear(1,$snapshot['token'],true);check($cleared===$snapshot['count']&&History::snapshot()['count']===0,'admin clear removes all visible local history entries');
    $draft=Publisher::find($savedIds[1]);check($draft['status']==='draft'&&count(Attachments::all((int)$draft['id']))===1,'clearing history retains active local drafts and attachments');
    check(count($mock->state['posts'])===$countPosts&&count($mock->state['media'])===$countMedia&&(int)DB::query('SELECT COUNT(*) FROM publishing_attempts')->fetchColumn()===$attemptCount,'local history deletion never changes WordPress content or publishing audits');
    file_put_contents($fixture,json_encode($mock->state,JSON_THROW_ON_ERROR));
    [$pubSession,$pubToken]=seedSession(2,'History publisher');[$code]=request('/history/clear',$pubSession,['_token'=>$pubToken,'confirmed'=>'1','snapshot_token'=>History::snapshot()['token']]);check($code===403,'bulk history HTTP endpoint rejects Publisher');
    [$code]=request('/history/delete',$httpAdmin,['_token'=>'wrong','confirmed'=>'1','kind'=>'announcement','id'=>$savedIds[1]]);check($code===419,'history deletion endpoint enforces CSRF');
    [$code]=request('/history/delete?id='.$savedIds[1],$httpAdmin);check($code===404,'GET cannot delete history');
    [$code,$html]=request('/history',$httpAdmin);check($code===200&&str_contains($html,'Active drafts and pending work')&&str_contains($html,'Multi upload 1'),'active draft stays accessible after clear');
    sendMock($savedIds[1]);check(History::snapshot()['count']>0,'new publishing activity reappears after history clear');
    file_put_contents($fixture,json_encode($mock->state,JSON_THROW_ON_ERROR));
    if(in_array('--browser',$argv,true)){$process=proc_open(['node',ROOT.'/tests/multi-history-browser.cjs','http://127.0.0.1:'.$port.'/paleco-hub',$httpAdmin,$pubSession],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,ROOT);check(proc_close($process)===0,'multi-poster and history browser workflows');}
}finally{foreach($testFiles as $path)if(is_file($path))unlink($path);}
