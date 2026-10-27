<?php
namespace App\Services;
use App\Models\DB;
final class Publisher {
    public function __construct(private readonly WordPressTransport $transport=new CurlWordPressTransport()) {}
    public static function authorize(int $userId): void {
        if (!DB::query('SELECT 1 FROM users WHERE id=? AND is_active=1 AND role_id IN (1,2)',[$userId])->fetchColumn()) throw new \RuntimeException('Only active Administrators and Publishers may manage announcements.');
    }
    public static function find(int $id): array {
        $a=DB::query('SELECT * FROM announcements WHERE id=?',[$id])->fetch();
        if (!$a) throw new \RuntimeException('Announcement not found.'); return $a;
    }
    public static function connection(): array {
        $c=DB::query('SELECT * FROM wordpress_connections WHERE id=1')->fetch();
        if (!$c) throw new \RuntimeException('An administrator must configure the WordPress connection first.'); return $c;
    }
    public static function mapping(string $type): array {
        $mapping=DB::query('SELECT * FROM category_mappings WHERE module=?',[$type])->fetch();
        if (!$mapping || !$mapping['category_name']) throw new \RuntimeException('An administrator must configure the required WordPress category.'); return $mapping;
    }
    public static function reviewToken(array $a): string {
        $c=DB::query('SELECT * FROM wordpress_connections WHERE id=1')->fetch() ?: [];
        $mapping=self::mapping($a['type']);
        $key=base64_decode(env('APP_KEY'),true); if (!$key || strlen($key)!==32) throw new \RuntimeException('A valid server encryption key is required.');
        return hash_hmac('sha256',json_encode([$a['id'],$a['revision'],$a['title'],$a['body'],$a['image_path'],$a['interruption_date'],$c,$mapping],JSON_THROW_ON_ERROR),$key);
    }
    public static function status(array $a): string {
        return match($a['status']) { 'draft'=>'Local Draft','wordpress_draft'=>'WordPress Draft','publishing'=>'Publishing','published'=>'Published','failed'=>'Failed',default=>ucfirst($a['status']) };
    }
    public static function remoteLink(array $a): ?string {
        $link=$a['wordpress_post_url'] ?? null; if (!$link || !$a['wordpress_destination']) return null;
        $client=new WordPressClient(['website_url'=>$a['wordpress_destination']]);
        try { return $client->safeLink($link); } catch (\Throwable $ex) { return null; }
    }
    /** A successful repeated action on the same revision returns the existing remote identity. */
    public function send(int $id,int $userId,string $action,int $revision,string $reviewToken,bool $confirmed): array {
        self::authorize($userId);
        if (!in_array($action,['draft','publish'],true)) throw new \RuntimeException('Choose WordPress Draft or Publish.');
        if ($action==='publish' && !$confirmed) throw new \RuntimeException('Explicit confirmation is required before public publication.');
        return AnnouncementLock::run($id,function() use ($id,$userId,$action,$revision,$reviewToken) {
            $a=self::find($id); self::authorize($userId);
            if ((int)$a['revision']!==$revision || !hash_equals(self::reviewToken($a),$reviewToken)) throw new \RuntimeException('The announcement or connection changed. Refresh the review and confirm the current version.');
            $c=self::connection(); $destination=rtrim($c['rest_url'],'/');
            if ($a['wordpress_destination'] && $a['wordpress_destination']!==$destination) throw new \RuntimeException('This announcement belongs to a different WordPress destination. Restore its original connection before updating or reconciling it.');
            if ($a['wordpress_status']==='publish' && $action==='draft') throw new \RuntimeException('This announcement is already public. Use Publish to update it; saving a WordPress Draft cannot unpublish it.');
            if (!$a['uncertain_stage'] && (int)$a['synced_revision']===$revision && $a['wordpress_status']===$action) return $a;
            $client=new WordPressClient($c,$this->transport); $attempt=0; $writing=false;
            try {
                DB::query("UPDATE publishing_attempts SET outcome='uncertain',message='A previous request stopped before recording its result.',finished_at=UTC_TIMESTAMP() WHERE announcement_id=? AND outcome='publishing'",[$id]);
                DB::query('INSERT INTO publishing_attempts (announcement_id,user_id,action,revision) VALUES (?,?,?,?)',[$id,$userId,$action,$revision]);
                $attempt=(int)DB::connection()->lastInsertId();
                DB::query("UPDATE announcements SET status='publishing',last_publish_attempt_at=UTC_TIMESTAMP(),publish_error=NULL WHERE id=?",[$id]);
                $client->permissions($action,$a['type']==='scheduled');
                if ($a['uncertain_stage']==='attachment') {
                    $pending=json_decode($a['pending_payload'] ?? '',true);
                    if (!is_array($pending) || !isset($pending['remote'],$pending['media_id'])) throw new WordPressFailure('The poster attachment needs administrator reconciliation.',true);
                    $writing=true;
                    $this->attachMedia($client,(int)$pending['media_id'],(int)$pending['remote']['id']);
                    $this->finish($id,$userId,$attempt,$pending['remote'],'Reconciled the poster attachment and WordPress post.');
                    return self::find($id);
                }
                if ($a['uncertain_stage']==='post') {
                    $payload=json_decode($a['pending_payload'] ?? '',true);
                    if (!is_array($payload)) throw new WordPressFailure('A previous write has an uncertain result. Contact an administrator; do not create another post.',true);
                    $remote=$this->reconcilePost($client,$a,$payload);
                    if ($a['type']==='scheduled') {
                        $this->prepareAttachment($id,$remote,(int)$a['wordpress_media_id']);
                        $writing=true;
                        $this->attachMedia($client,(int)$a['wordpress_media_id'],$remote['id']);
                    }
                    $this->finish($id,$userId,$attempt,$remote,'Reconciled the previous WordPress write.');
                    $a=self::find($id);
                    // Reconciliation never changes a remote post to a different requested status automatically.
                    return $a;
                }
                if ($a['uncertain_stage']==='media') {
                    $pending=json_decode($a['pending_payload'] ?? '',true);
                    if (!is_array($pending) || !isset($pending['slug'],$pending['marker'],$pending['hash'])) throw new WordPressFailure('A prior media upload needs administrator reconciliation.',true);
                    $matches=$client->request('GET','/media?context=edit&slug='.rawurlencode($pending['slug']).'&per_page=100');
                    if (count($matches)!==1) throw new WordPressFailure('The earlier poster upload is still uncertain. No new upload was made. Retry reconciliation later or ask an administrator to inspect WordPress Media.',true);
                    $media=$client->media($matches[0],$pending['marker']);
                    $this->saveMedia($id,$media,$pending['hash']); $a=self::find($id);
                }
                $category=$client->category(self::mapping($a['type']));
                if (!trim($a['title']) || mb_strlen($a['title'])>255) throw new \RuntimeException('A valid announcement title is required.');
                if ($a['type']==='partial' && !trim($a['body'] ?? '')) throw new \RuntimeException('Announcement text is required.');
                $remoteKey=$a['remote_key'] ?: 'paleco-hub-'.$id.'-'.bin2hex(random_bytes(12));
                DB::query('UPDATE announcements SET remote_key=?,wordpress_destination=? WHERE id=?',[$remoteKey,$destination,$id]); $a['remote_key']=$remoteKey;
                $payload=['slug'=>$remoteKey,'status'=>$action,'title'=>e($a['title']),'content'=>'','categories'=>[$category]];
                if ($a['type']==='scheduled') {
                    Announcement::dateTitle($a['interruption_date'] ?? '');
                    $path=Uploads::path($a['image_path'] ?? ''); $mime=Uploads::validate($path); $hash=hash_file('sha256',$path);
                    if (!$a['wordpress_media_id'] || $a['wordpress_media_hash']!==$hash) {
                        $slug=$remoteKey.'-'.substr($hash,0,16); $marker='paleco-hub-media:'.$remoteKey.':'.$hash;
                        $pending=['slug'=>$slug,'marker'=>$marker,'hash'=>$hash];
                        $this->pending($id,'media',$pending);
                        $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
                        $writing=true;
                        $result=$client->request('POST','/media',['slug'=>$slug,'title'=>$payload['title'],'description'=>$marker,'alt_text'=>$a['title']],['path'=>$path,'mime'=>$mime,'name'=>$slug.'.'.$extension]);
                        $media=$client->media($result,$marker); $this->saveMedia($id,$media,$hash); $writing=false; $a=self::find($id);
                    }
                    $imageUrl=$client->safeLink($a['wordpress_media_url']);
                    $payload['featured_media']=(int)$a['wordpress_media_id'];
                    $payload['content']='<figure class="wp-block-image"><img src="'.e($imageUrl).'" alt="'.e($a['title']).'" class="wp-image-'.(int)$a['wordpress_media_id'].'" /></figure>';
                } else {
                    $payload['content']='<p>'.nl2br(e(str_replace(["\r\n","\r"],"\n",$a['body'])),false).'</p>';
                }
                $payload['content'].="\n<!-- paleco-hub:".$remoteKey.' -->';
                // Verify ownership marker before updating a previously recorded post.
                if ($a['wordpress_post_id']) {
                    $existing=$client->request('GET','/posts/'.(int)$a['wordpress_post_id'].'?context=edit');
                    $this->ownedPost($existing,$remoteKey);
                    if (($existing['status'] ?? '')==='publish' && $action==='draft') throw new \RuntimeException('The remote post is public. Review and publish an update instead of demoting it to a draft.');
                }
                $this->pending($id,'post',$payload);
                $endpoint='/posts'.($a['wordpress_post_id']?'/'.(int)$a['wordpress_post_id']:'');
                $writing=true;
                $result=$client->request('POST',$endpoint,$payload);
                $remote=$client->post($result,$payload,$a['wordpress_post_id']?(int)$a['wordpress_post_id']:null);
                if ($a['type']==='scheduled') {
                    $this->prepareAttachment($id,$remote,(int)$a['wordpress_media_id']);
                    $this->attachMedia($client,(int)$a['wordpress_media_id'],$remote['id']);
                }
                $this->finish($id,$userId,$attempt,$remote,$action==='publish'?'Published to WordPress.':'Saved as a WordPress draft.');
                return self::find($id);
            } catch (\Throwable $ex) {
                // A failure to commit a known-successful response must also leave its pending marker intact.
                if (!$attempt) throw $ex;
                $current=self::find($id);
                $uncertain=$current['uncertain_stage']!==null;
                if ($writing && $current['uncertain_stage']!=='attachment' && $ex instanceof WordPressFailure && !$ex->uncertain) { $uncertain=false; DB::query('UPDATE announcements SET uncertain_stage=NULL,pending_payload=NULL WHERE id=?',[$id]); }
                $safe=($ex instanceof WordPressFailure || get_class($ex)===\RuntimeException::class)?$ex->getMessage():'The result could not be recorded. Retry reconciliation before another write.';
                $safe=mb_substr($safe,0,500);
                DB::query("UPDATE announcements SET status='failed',publish_error=? WHERE id=?",[$safe,$id]);
                DB::query('UPDATE publishing_attempts SET outcome=?,message=?,wordpress_post_id=?,finished_at=UTC_TIMESTAMP() WHERE id=?',[$uncertain?'uncertain':'failed',$safe,$current['wordpress_post_id'],$attempt]);
                DB::query("INSERT INTO publishing_history (announcement_id,user_id,status,wordpress_post_id,message) VALUES (?,?,'failed',?,?)",[$id,$userId,$current['wordpress_post_id'],mb_substr($safe,0,255)]);
                throw new \RuntimeException($safe);
            }
        });
    }
    private function pending(int $id,string $stage,array $payload): void {
        DB::query('UPDATE announcements SET uncertain_stage=?,pending_payload=? WHERE id=?',[$stage,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id]);
    }
    private function saveMedia(int $id,array $media,string $hash): void {
        DB::query('UPDATE announcements SET wordpress_media_id=?,wordpress_media_url=?,wordpress_media_hash=?,uncertain_stage=NULL,pending_payload=NULL WHERE id=?',[$media['id'],$media['url'],$hash,$id]);
    }
    private function prepareAttachment(int $id,array $remote,int $mediaId): void {
        // Record the accepted post identity before another network operation can fail.
        DB::query('UPDATE announcements SET wordpress_post_id=?,wordpress_post_url=?,wordpress_status=? WHERE id=?',[$remote['id'],$remote['url'],$remote['status'],$id]);
        $this->pending($id,'attachment',['remote'=>$remote,'media_id'=>$mediaId]);
    }
    private function attachMedia(WordPressClient $client,int $mediaId,int $postId): void {
        $media=$client->request('GET','/media/'.$mediaId.'?context=edit');
        if (($media['post'] ?? 0)===$postId) return;
        $media=$client->request('POST','/media/'.$mediaId,['post'=>$postId]);
        if (($media['id'] ?? null)!==$mediaId || ($media['post'] ?? null)!==$postId) throw new WordPressFailure('WordPress could not confirm the poster attachment. Retry reconciliation; the post ID has been retained.',true);
    }
    private function ownedPost(array $post,string $key): void {
        if (!str_contains($post['content']['raw'] ?? '', '<!-- paleco-hub:'.$key.' -->')) throw new WordPressFailure('The remote post identity could not be verified. Contact an administrator before updating it.',true);
    }
    private function reconcilePost(WordPressClient $client,array $a,array $payload): array {
        if ($a['wordpress_post_id']) $matches=[$client->request('GET','/posts/'.(int)$a['wordpress_post_id'].'?context=edit')];
        else $matches=$client->request('GET','/posts?context=edit&status=any&slug='.rawurlencode($a['remote_key']).'&per_page=100');
        if (count($matches)!==1) throw new WordPressFailure('The earlier post write is still uncertain. No new post was created. Retry reconciliation later or ask an administrator to inspect WordPress.',true);
        $this->ownedPost($matches[0],$a['remote_key']);
        return $client->post($matches[0],$payload,$a['wordpress_post_id']?(int)$a['wordpress_post_id']:null);
    }
    private function finish(int $id,int $userId,int $attempt,array $remote,string $message): void {
        $db=DB::connection(); $db->beginTransaction();
        try {
            $status=$remote['status']==='publish'?'published':'wordpress_draft';
            DB::query('UPDATE announcements SET wordpress_post_id=?,wordpress_post_url=?,wordpress_status=?,status=?,synced_revision=revision,wordpress_saved_at=UTC_TIMESTAMP(),published_at=IF(?=\'publish\',COALESCE(published_at,UTC_TIMESTAMP()),published_at),uncertain_stage=NULL,pending_payload=NULL,publish_error=NULL WHERE id=?',[$remote['id'],$remote['url'],$remote['status'],$status,$remote['status'],$id]);
            DB::query('UPDATE publishing_attempts SET outcome=?,wordpress_post_id=?,message=?,finished_at=UTC_TIMESTAMP() WHERE id=?',[$status,$remote['id'],$message,$attempt]);
            DB::query('INSERT INTO publishing_history (announcement_id,user_id,status,wordpress_post_id,message) VALUES (?,?,?,?,?)',[$id,$userId,$status,$remote['id'],$message]);
            DB::audit($userId,'wordpress.'.$status); $db->commit();
        } catch (\Throwable $ex) { $db->rollBack(); throw $ex; }
    }
}
