<?php
use App\Services\{Announcement,PartialPublication,Publisher};
use App\Models\DB;
function partialForm(string $body,string $title=''): array {
    $input=['operation'=>bin2hex(random_bytes(32)),'id'=>'0','revision'=>'0','operation_revision'=>'0','body'=>$body,'title'=>$title];
    $prepared=PartialPublication::prepare(2,$input);
    return array_merge($input,['operation_revision'=>$prepared['operation_revision'],'confirmation_token'=>$prepared['confirmation_token'],'confirmed'=>'1']);
}
$advisory="⚡️ Power Service Advisory Final Update\r\n\r\nBárangay Maligaya: supply restored.\r\nThank you.";
check(Announcement::title($advisory)==='⚡️ Power Service Advisory Final Update','emoji/Unicode first-line title is preserved');
check(str_contains(Announcement::title('Supply restored in Bárangay Maligaya.'),'Bárangay Maligaya'),'fallback title derives from advisory content');
check(Announcement::title('')==='','empty advisory has no invented title');
check(mb_strlen(Announcement::title(str_repeat('a',100000)))<=255,'long Facebook text generates bounded title');
check(!str_contains(Announcement::publicationBody($advisory),'Power Service Advisory'),'detected title is removed from publication body once');
$before=(int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn();$calls=count($mock->state['calls']);
$form=partialForm($advisory);check((int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$before&&count($mock->state['calls'])===$calls,'confirmation preparation creates no local or remote draft');
$unconfirmed=$form;$unconfirmed['confirmed']='0';rejects(fn()=>PartialPublication::publish($publisherService,2,$unconfirmed),'unconfirmed direct form cannot publish');
check((int)DB::query('SELECT COUNT(*) FROM announcements')->fetchColumn()===$before,'unconfirmed Publish creates no local record');
$changed=$form;$changed['body'].='Changed after confirmation';rejects(fn()=>PartialPublication::publish($publisherService,2,$changed),'changed modal content invalidates confirmation signature');
$a=PartialPublication::publish($publisherService,2,$form);$post=$mock->state['posts'][$a['wordpress_post_id']];
check($a['status']==='published'&&$post['status']==='publish'&&$post['categories']===[58],'direct partial form publishes to POWER_ADVISORY without draft status');
check(!str_contains($post['content']['raw'],'Power Service Advisory'),'WordPress body does not duplicate its detected title');
$count=writeCount();$again=PartialPublication::publish($publisherService,2,$form);check($again['id']===$a['id']&&writeCount()===$count,'duplicate confirmed form reuses operation/post instead of creating duplicate');
$manual=partialForm($advisory,'Publisher override');$a=PartialPublication::publish($publisherService,2,$manual);check($a['title']==='Publisher override','server retains manually edited title');
rejects(fn()=>partialForm(''),'empty text rejected before local persistence');rejects(fn()=>partialForm('Power Service Advisory'),'title-only advisory rejected with actionable validation');rejects(fn()=>partialForm(str_repeat('x',100001)),'oversize advisory rejected');
rejects(fn()=>partialForm("\u{00A0}\u{200B}"),'Unicode whitespace-only advisory rejected');
rejects(fn()=>partialForm("Invalid\0text"),'unsupported control characters rejected');
rejects(fn()=>PartialPublication::prepare($viewer,$form),'ordinary users cannot prepare a publication');
$mock->state['mode']='post-timeout';$timeout=partialForm($advisory,'Uncertain direct form');rejects(fn()=>PartialPublication::publish($publisherService,2,$timeout),'direct form preserves uncertain post result');$entry=PartialPublication::lookup(2,$timeout['operation']);check(Publisher::find($entry['id'])['status']==='failed','failed direct attempt is tracked as failed rather than a draft');$count=writeCount();$a=PartialPublication::publish($publisherService,2,$timeout);check(writeCount()===$count&&$a['status']==='published','direct form retry reconciles accepted post without another creation');
// Refresh the fake server state after service-only tests; never use production credentials.
file_put_contents($fixture,json_encode($mock->state,JSON_THROW_ON_ERROR));
[$code,$html]=request('/partial',$httpAdmin);check($code===200&&!str_contains($html,'Detect title from announcement')&&!str_contains($html,'Save draft')&&!str_contains($html,'Save and review for WordPress'),'partial main form removes title and draft action controls');
[$code]=request('/partial/title',$httpAdmin,['_token'=>'bad','body'=>$advisory]);check($code===419,'automatic detection endpoint enforces CSRF');
[$code,$html]=request('/partial/publish',$httpAdmin,['_token'=>'bad','title'=>'Preserved expired form','body'=>$advisory]);check($code===419&&str_contains($html,'Preserved expired form')&&str_contains($html,'Bárangay Maligaya'),'expired publishing CSRF preserves authorized user form content');
[$code,$json]=request('/partial/title',$httpAdmin,['_token'=>$httpAdminCsrf,'body'=>$advisory]);check($code===200&&json_decode($json,true)['title']==='⚡️ Power Service Advisory Final Update','authenticated automatic title endpoint uses shared PHP rules');
[$code]=request('/partial/publish',$httpViewer,['_token'=>$httpViewerCsrf]);check($code===403,'direct partial form endpoint enforces publisher role');
[$code,$html]=request('/partial/publish',$httpAdmin,['_token'=>$httpAdminCsrf,'confirmed'=>'1','operation'=>bin2hex(random_bytes(32)),'id'=>'0','revision'=>'0','title'=>'Invalid encoded text','body'=>"Invalid \xff text"]);check($code===422&&str_contains($html,'UTF-8')&&str_contains($html,'Invalid encoded text'),'invalid encoding returns actionable form feedback without a rendering failure');
$httpInput=partialForm($advisory,'Preserved on failure');$httpInput['_token']=$httpAdminCsrf;
// Signatures are user-bound, so prepare using the same Administrator as the HTTP session.
$prepared=PartialPublication::prepare(1,$httpInput);$httpInput['confirmation_token']=$prepared['confirmation_token'];
$fixtureState=$mock->state;$fixtureState['mode']='auth';file_put_contents($fixture,json_encode($fixtureState,JSON_THROW_ON_ERROR));
[$code,$html]=request('/partial/publish',$httpAdmin,$httpInput);check($code===422&&str_contains($html,'Preserved on failure')&&str_contains($html,'Bárangay Maligaya')&&str_contains($html,'authentication failed'),'WordPress failure preserves submitted form content and actionable error');
$fixtureState=json_decode(file_get_contents($fixture),true);$fixtureState['mode']='ok';file_put_contents($fixture,json_encode($fixtureState));
if(in_array('--browser',$argv,true)){
    $browserProcess=proc_open(['node',ROOT.'/tests/partial-browser.cjs','http://127.0.0.1:'.$port.'/paleco-hub',$httpAdmin,$fixture],[0=>STDIN,1=>STDOUT,2=>STDERR],$browserPipes,ROOT);
    check(proc_close($browserProcess)===0,'partial form title/cancel/confirmation/failure browser checks');
}
