<?php
namespace App\Services;
use App\Models\DB;
/** Direct, confirmed partial publication. Uses existing settings storage for durable operation identity. */
final class PartialPublication {
    private static function metadata(array $input): array {
        $operation=$input['operation'] ?? '';
        if (!is_string($operation) || !preg_match('/^[a-f0-9]{64}$/D',$operation)) throw new \RuntimeException('Refresh this form before publishing.');
        foreach(['id','revision','operation_revision'] as $key) if(isset($input[$key]) && (!is_scalar($input[$key]) || !ctype_digit((string)$input[$key]))) throw new \RuntimeException('Invalid form version. Refresh and try again.');
        return ['operation'=>$operation,'id'=>(int)($input['id'] ?? 0),'revision'=>(int)($input['revision'] ?? 0),'operation_revision'=>(int)($input['operation_revision'] ?? 0)];
    }
    private static function key(int $userId,string $operation): string { return 'partial_publish_'.hash('sha256',$userId.':'.$operation); }
    public static function lookup(int $userId,string $operation): ?array {
        $value=DB::query('SELECT setting_value FROM application_settings WHERE setting_key=?',[self::key($userId,$operation)])->fetchColumn();
        return $value?json_decode($value,true,512,JSON_THROW_ON_ERROR):null;
    }
    private static function signature(int $userId,array $meta,array $content): string {
        $base=['id'=>$meta['id'],'revision'=>$meta['revision'],'title'=>'','body'=>'','image_path'=>null,'interruption_date'=>null,'type'=>'partial'];
        return hash_hmac('sha256',json_encode([$userId,$meta,$content],JSON_THROW_ON_ERROR),Publisher::reviewToken($base));
    }
    public static function prepare(int $userId,array $input): array {
        Publisher::authorize($userId); $meta=self::metadata($input); $content=Announcement::partialInput($input);
        $entry=self::lookup($userId,$meta['operation']);
        if ($entry) {
            if ($entry['original_id']!==$meta['id'] || $entry['original_revision']!==$meta['revision']) throw new \RuntimeException('This publication request belongs to another form. Reload the announcement.');
            $record=Publisher::find($entry['id']);
            if ((int)$record['revision']!==$entry['revision']) throw new \RuntimeException('This announcement changed in another tab. Open its current version before publishing.');
            if ($record['uncertain_stage'] && ($record['body']!==$content['body'] || $record['title']!==$content['title'])) throw new \RuntimeException('Reconcile the previous content before changing it. Restore the submitted text or open its publishing history.');
            $meta['operation_revision']=$entry['revision'];
        } elseif($meta['id']) {
            $record=Publisher::find($meta['id']);
            if($record['type']!=='partial' || (int)$record['revision']!==$meta['revision']) throw new \RuntimeException('The announcement changed. Open its current version before publishing.');
            if($record['uncertain_stage']) throw new \RuntimeException('Reconcile the previous WordPress result from publishing history before editing.');
        }
        return ['title'=>$content['title'],'body'=>Announcement::publicationBody($content['body']),'operation_revision'=>$meta['operation_revision'],'confirmation_token'=>self::signature($userId,$meta,$content)];
    }
    public static function publish(Publisher $publisher,int $userId,array $input): array {
        Publisher::authorize($userId);
        if (($input['confirmed'] ?? '')!=='1') throw new \RuntimeException('Confirm public publication before submitting this announcement.');
        $meta=self::metadata($input); $content=Announcement::partialInput($input);
        if (!is_string($input['confirmation_token'] ?? null) || !hash_equals(self::signature($userId,$meta,$content),$input['confirmation_token'])) throw new \RuntimeException('The advisory or WordPress settings changed. Review and confirm the current content again.');
        $key=self::key($userId,$meta['operation']); $lock='hub-partial-'.substr(hash('sha256',env('DB_DATABASE').$key),0,40);
        if ((int)DB::query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) throw new \RuntimeException('This publication is already processing. Retry this same form to check its result.');
        try {
            $entry=self::lookup($userId,$meta['operation']); $hash=hash('sha256',json_encode($content,JSON_THROW_ON_ERROR));
            if ($entry && ($entry['original_id']!==$meta['id'] || $entry['original_revision']!==$meta['revision'])) throw new \RuntimeException('This request belongs to another form. Reload the announcement.');
            $id=$entry['id'] ?? $meta['id'];
            return AnnouncementLock::run($id,function() use($publisher,$userId,$meta,$content,$entry,$hash,$key,$id) {
                $old=$id?Publisher::find($id):null;
                if($old && $old['type']!=='partial') throw new \RuntimeException('This form only publishes partial updates.');
                if($entry && (int)$old['revision']!==$entry['revision']) throw new \RuntimeException('A newer version exists. Reload before publishing.');
                if($entry && $entry['hash']!==$hash && $meta['operation_revision']!==$entry['revision']) throw new \RuntimeException('This confirmation is stale. Review the current text again.');
                if(!$entry && $old && (int)$old['revision']!==$meta['revision']) throw new \RuntimeException('A newer version exists. Reload before publishing.');
                $changed=$old && ($old['body']!==$content['body'] || $old['title']!==$content['title']);
                if($changed && ($old['uncertain_stage'] || $old['status']==='publishing')) throw new \RuntimeException('Reconcile the pending WordPress result before editing this content.');
                $db=DB::connection(); $db->beginTransaction();
                try {
                    if(!$old) {
                        DB::query("INSERT INTO announcements(user_id,type,title,body,status) VALUES (?,'partial',?,?,'publishing')",[$userId,$content['title'],$content['body']]);
                        $id=(int)$db->lastInsertId(); $revision=1;
                    } else {
                        $revision=(int)$old['revision'];
                        if($changed) { DB::query('UPDATE announcements SET title=?,body=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$content['title'],$content['body'],$id]); $revision++; }
                    }
                    $value=['id'=>$id,'revision'=>$revision,'hash'=>$hash,'original_id'=>$meta['id'],'original_revision'=>$meta['revision']];
                    DB::query('INSERT INTO application_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,json_encode($value,JSON_THROW_ON_ERROR)]);
                    DB::audit($userId,'partial.publication_confirmed'); $db->commit();
                } catch(\Throwable $ex) { $db->rollBack(); throw $ex; }
                $record=Publisher::find($id);
                try { return $publisher->send($id,$userId,'publish',(int)$record['revision'],Publisher::reviewToken($record),true); }
                catch(\Throwable $ex) {
                    $safe=($ex instanceof WordPressFailure || get_class($ex)===\RuntimeException::class)?$ex->getMessage():'Publishing could not be completed. Retry this same form to reconcile its result.';
                    DB::query("UPDATE announcements SET status='failed',publish_error=? WHERE id=? AND status='publishing'",[mb_substr($safe,0,500),$id]);
                    throw $ex;
                }
            });
        } finally { DB::query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
}
