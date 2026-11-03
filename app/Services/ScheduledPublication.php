<?php
namespace App\Services;
use App\Models\DB;
/** Confirmed upload publication using the same durable operation storage as partial updates. */
final class ScheduledPublication {
    public static function publish(Publisher $publisher,PosterOcr $ocr,int $userId,array $input,?array $upload): array {
        Publisher::authorize($userId);
        $operation=$input['operation'] ?? '';
        if(!is_string($operation)||!preg_match('/^[a-f0-9]{64}$/D',$operation)||($input['confirmed'] ?? '')!=='1')throw new \RuntimeException('Confirm this scheduled publication before submitting.');
        foreach(['id','revision'] as $field)if(isset($input[$field])&&(!is_scalar($input[$field])||!ctype_digit((string)$input[$field])))throw new \RuntimeException('Invalid announcement version. Reload its current record.');
        $files=Attachments::files($upload);
        foreach($files as $file)if($file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))throw new \RuntimeException('A poster upload did not complete. Select images up to 8 MB each and retry.');
        $key='scheduled_publish_'.hash('sha256',$userId.':'.$operation);
        $lock='hub-scheduled-'.substr(hash('sha256',env('DB_DATABASE').$key),0,40);
        if((int)DB::query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new \RuntimeException('Publication is processing. Retry this same form to check its result.');
        try {
            $hash=hash('sha256',json_encode([$input['id'] ?? '0',$input['revision'] ?? '0',$input['title'] ?? '',$input['date'] ?? '',$input['date_source'] ?? '',$input['remove_attachments'] ?? [],array_map(fn($f)=>hash_file('sha256',$f['tmp_name']),$files)],JSON_THROW_ON_ERROR));
            $stored=DB::query('SELECT setting_value FROM application_settings WHERE setting_key=?',[$key])->fetchColumn();
            if($stored){
                $entry=json_decode($stored,true,512,JSON_THROW_ON_ERROR);
                if(!hash_equals($entry['hash'],$hash))throw new \RuntimeException('This submission already has saved posters. Restore the submitted data to retry, or open Publishing History to reconcile/edit the saved announcement.');
                $record=Publisher::find($entry['id']);
                if((int)$record['revision']!==$entry['revision'])throw new \RuntimeException('This announcement changed. Open its current version in Publishing History.');
            }else{
                $id=ScheduledDrafts::save($userId,$input,$upload,$ocr);$record=Publisher::find($id);
                DB::query('INSERT INTO application_settings(setting_key,setting_value) VALUES (?,?)',[$key,json_encode(['id'=>$id,'revision'=>(int)$record['revision'],'hash'=>$hash],JSON_THROW_ON_ERROR)]);
            }
            try { $record=$publisher->send((int)$record['id'],$userId,'publish',(int)$record['revision'],Publisher::reviewToken($record),true); }
            catch(\Throwable $ex){
                $pending=Publisher::find((int)$record['id']);
                if($pending['wordpress_status']==='publish' && $pending['wordpress_post_id'])throw new \RuntimeException('WordPress has published this announcement, but local finalization is incomplete. Its post ID and permalink are retained. Retry this unchanged form to reconcile; do not start another publication.',0,$ex);
                throw $ex;
            }
            $history=DB::query("SELECT 1 FROM publishing_history WHERE announcement_id=? AND wordpress_post_id=? AND status='published' LIMIT 1",[$record['id'],$record['wordpress_post_id']])->fetchColumn();
            if($record['status']!=='published'||$record['wordpress_status']!=='publish'||!$record['wordpress_post_id']||$record['uncertain_stage']||(int)$record['synced_revision']!==(int)$record['revision']||!$history)throw new \RuntimeException('Publication is not finalized. Retry this same form to reconcile its result.');
            return ['status'=>'published','wordpress_status'=>'publish','wordpress_post_id'=>(int)$record['wordpress_post_id'],'wordpress_post_url'=>Publisher::remoteLink($record),'history_finalized'=>true,'next_operation'=>bin2hex(random_bytes(32))];
        }finally{DB::query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
