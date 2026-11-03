<?php
// Explicit single-record repair. Default preview is local and makes no HTTP requests.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\{Announcement,Drafts,Publisher};
try{
    $options=getopt('',['id:','user:','apply','confirm-public-update']);
    $id=(int)($options['id'] ?? 0);$user=(int)($options['user'] ?? 0);
    Publisher::authorize($user);$a=Publisher::find($id);
    if($a['type']!=='partial' || !$a['wordpress_post_id'])throw new RuntimeException('Choose an existing mapped partial advisory.');
    if($a['uncertain_stage'] || $a['status']==='publishing')throw new RuntimeException('Reconcile the pending result before repair.');
    if((int)$a['synced_revision']!==(int)$a['revision'])throw new RuntimeException('Review and synchronize unsaved local edits before repair.');
    $clean=Announcement::partialInput($a);
    echo 'Remote post ID: '.$a['wordpress_post_id']."\nStatus: ".$a['wordpress_status']."\nTitle: ".$a['title']."\nCategory/tag: POWER_ADVISORY\nReplacement raw content:\n".Announcement::wordpressText($clean['body'])."\n";
    if(!isset($options['apply'])){echo "Preview only. Add --apply to repair this post. Public posts also require --confirm-public-update.\n";exit;}
    if($a['wordpress_status']==='publish' && !isset($options['confirm-public-update']))throw new RuntimeException('Public repair requires --confirm-public-update.');
    Drafts::save('partial',$user,['id'=>$id,'revision'=>$a['revision'],'body'=>$a['body'],'title'=>$a['title']],null);
    $current=Publisher::find($id);
    $result=(new Publisher())->send($id,$user,$a['wordpress_status']==='publish'?'publish':'draft',(int)$current['revision'],Publisher::reviewToken($current),true);
    echo "Repaired existing post: ".($result['wordpress_post_url'] ?? '')."\n";
}catch(Throwable $ex){fwrite(STDERR,$ex instanceof RuntimeException?$ex->getMessage()."\n":"Repair failed; inspect local publishing history before retrying.\n");exit(1);}
