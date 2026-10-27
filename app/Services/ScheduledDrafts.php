<?php
namespace App\Services;
use App\Models\DB;
final class ScheduledDrafts {
    public static function save(int $userId,array $input,?array $upload,?RecognitionEngine $recognition=null): int {
        Publisher::authorize($userId);$id=(int)($input['id'] ?? 0);
        return AnnouncementLock::run($id,function()use($userId,$input,$upload,$recognition,$id){
            $old=$id?Publisher::find($id):null;
            if($old && ($old['type']!=='scheduled'||$old['uncertain_stage']||$old['status']==='publishing'))throw new \RuntimeException('Reconcile the pending WordPress result before editing posters.');
            if($old && (int)($input['revision'] ?? 0)!==(int)$old['revision'])throw new \RuntimeException('This announcement changed. Reload before saving.');
            $date=(string)($input['date'] ?? '');$fallback=Announcement::dateTitle($date);$title=(string)($input['title'] ?? '');if(!trim($title))$title=$fallback;
            if(mb_strlen($title)>255)throw new \RuntimeException('Enter a title up to 255 characters.');
            if(!isset($input['date_confirmed']))throw new \RuntimeException('Confirm that all selected posters correspond to the interruption date.');
            $existing=$id?Attachments::all($id):[];$remove=$input['remove_attachments'] ?? [];
            if(!is_array($remove))throw new \RuntimeException('Invalid poster selection.');
            $remove=array_map('intval',$remove);$allowed=array_map('intval',array_column($existing,'id'));
            if(array_diff($remove,$allowed))throw new \RuntimeException('A selected poster does not belong to this announcement.');
            $kept=array_values(array_filter($existing,fn($a)=>!in_array((int)$a['id'],$remove,true)));$files=Attachments::files($upload);
            if(count($kept)+count($files)<1 || count($kept)+count($files)>Attachments::limit())throw new \RuntimeException('Select between 1 and '.Attachments::limit().' posters, up to 8 MB each.');
            $engine=$recognition ?? new UnconfiguredRecognition();$staged=[];
            foreach($kept as $poster){$path=Uploads::path($poster['storage_name']);Uploads::validate($path);self::checkDates($engine->dates($path),$date,$input);}
            foreach($files as $file){
                    if($file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']))throw new \RuntimeException('A poster upload did not complete. Select valid images up to 8 MB each and try again.');
                $mime=Uploads::validate($file['tmp_name']);$dates=$engine->dates($file['tmp_name']);
                self::checkDates($dates,$date,$input);
                $staged[]=['storage_name'=>bin2hex(random_bytes(24)),'mime'=>$mime,'tmp'=>$file['tmp_name'],'hash'=>hash_file('sha256',$file['tmp_name'])];
            }
            $moved=[];$db=DB::connection();
            try{
                if(!is_dir(ROOT.'/storage/uploads'))mkdir(ROOT.'/storage/uploads',0700,true);
                foreach($staged as $file){$path=Uploads::path($file['storage_name']);if(!move_uploaded_file($file['tmp'],$path))throw new \RuntimeException('A poster could not be stored. No changes were saved.');$moved[]=$path;}
                $db->beginTransaction();
                $first=$kept[0] ?? $staged[0];$name=$first['storage_name'];$mime=$first['mime'];
                if($old)DB::query('UPDATE announcements SET title=?,interruption_date=?,image_path=?,image_mime=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$title,$date,$name,$mime,$id]);
                else DB::query("INSERT INTO announcements(user_id,type,title,interruption_date,image_path,image_mime) VALUES (?,'scheduled',?,?,?,?)",[$userId,$title,$date,$name,$mime]);
                $saved=$id ?: (int)$db->lastInsertId();
                foreach($remove as $attachmentId)DB::query('UPDATE announcement_attachments SET removed_at=UTC_TIMESTAMP() WHERE id=? AND announcement_id=?',[$attachmentId,$saved]);
                $position=0;foreach($kept as $attachment)DB::query('UPDATE announcement_attachments SET sort_order=? WHERE id=?',[$position++,$attachment['id']]);
                foreach($staged as $file)DB::query('INSERT INTO announcement_attachments(announcement_id,storage_name,mime,sort_order,content_hash) VALUES (?,?,?,?,?)',[$saved,$file['storage_name'],$file['mime'],$position++,$file['hash']]);
                // Mirror the first active attachment for legacy readers; retain all media on their own rows.
                $primary=Attachments::all($saved)[0];
                DB::query('UPDATE announcements SET wordpress_media_id=?,wordpress_media_url=?,wordpress_media_hash=? WHERE id=?',[$primary['wordpress_media_id'],$primary['wordpress_media_url'],$primary['content_hash'],$saved]);
                DB::audit($userId,'scheduled.posters_saved');$db->commit();return $saved;
            }catch(\Throwable $ex){if($db->inTransaction())$db->rollBack();foreach($moved as $path)if(is_file($path))unlink($path);throw $ex;}
        });
    }
    private static function checkDates(array $dates,string $date,array $input): void {
        if($dates && !in_array($date,$dates,true) && !isset($input['date_conflict_confirmed']))throw new \RuntimeException('A poster contains a different detected date. Review all posters and explicitly confirm the chosen date.');
        if(count(array_unique($dates))>1 && !isset($input['date_conflict_confirmed']))throw new \RuntimeException('Multiple dates were detected. Review all posters and explicitly confirm the chosen date.');
    }
}
