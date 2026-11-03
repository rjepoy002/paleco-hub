<?php
namespace App\Services;
use App\Models\DB;
final class ScheduledDrafts {
    public static function save(int $userId,array $input,?array $upload,RecognitionEngine|PosterOcr|null $recognition=null): int {
        Publisher::authorize($userId);$id=(int)($input['id'] ?? 0);
        return AnnouncementLock::run($id,function()use($userId,$input,$upload,$recognition,$id){
            $old=$id?Publisher::find($id):null;
            if($old && ($old['type']!=='scheduled'||$old['uncertain_stage']||$old['status']==='publishing'))throw new \RuntimeException('Reconcile the pending WordPress result before editing posters.');
            if($old && (int)($input['revision'] ?? 0)!==(int)$old['revision'])throw new \RuntimeException('This announcement changed. Reload before saving.');
            $date=(string)($input['date'] ?? '');$title=(string)($input['title'] ?? '');
            if(mb_strlen($title)>255)throw new \RuntimeException('Enter a title up to 255 characters.');
            $existing=$id?Attachments::all($id):[];$remove=$input['remove_attachments'] ?? [];
            if(!is_array($remove))throw new \RuntimeException('Invalid poster selection.');
            $remove=array_map('intval',$remove);$allowed=array_map('intval',array_column($existing,'id'));
            if(array_diff($remove,$allowed))throw new \RuntimeException('A selected poster does not belong to this announcement.');
            $kept=array_values(array_filter($existing,fn($a)=>!in_array((int)$a['id'],$remove,true)));$files=Attachments::files($upload);
            if(count($kept)+count($files)<1 || count($kept)+count($files)>Attachments::limit())throw new \RuntimeException('Select between 1 and '.Attachments::limit().' posters, up to 8 MB each.');
            $engine=$recognition instanceof PosterOcr?$recognition:new PosterOcr();$staged=[];$results=[];
            $scan=function(string $path)use($engine,$recognition):array {
                if($recognition instanceof RecognitionEngine){$dates=$recognition->dates($path);return ['status'=>count($dates)>1?'conflict':($dates?'valid':'missing'),'date'=>count($dates)===1?$dates[0]:null,'candidates'=>$dates,'confidence'=>0.9,'text'=>'','hash'=>hash_file('sha256',$path),'provider'=>'legacy','message'=>'Legacy recognizer result.'];}
                return $engine->scan($path);
            };
            foreach($kept as $poster){$path=Uploads::path($poster['storage_name']);Uploads::validate($path);$results['saved:'.$poster['id']]=$scan($path);}
            foreach($files as $file){
                if($file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))throw new \RuntimeException('A poster upload did not complete. Use images up to 8 MB each.');
                $mime=Uploads::validate($file['tmp_name']);$result=$scan($file['tmp_name']);
                $staged[]=['storage_name'=>bin2hex(random_bytes(24)),'mime'=>$mime,'tmp'=>$file['tmp_name'],'hash'=>hash_file('sha256',$file['tmp_name']),'ocr'=>$result];$results[]=$result;
            }
            $dates=array_values(array_unique(array_filter(array_column($results,'date'))));
            $autoDetected=false;if($date===''&&count($dates)===1&&count(array_filter($results,fn($r)=>$r['status']==='valid'))===count($results)){$date=$dates[0];$autoDetected=true;}
            $fallback=Announcement::dateTitle($date);if(!trim($title))$title=$fallback;
            $source=$autoDetected||($input['date_source'] ?? 'manual')==='ocr'?'ocr':'manual';
            $moved=[];$db=DB::connection();
            try{
                if(!is_dir(ROOT.'/storage/uploads'))mkdir(ROOT.'/storage/uploads',0700,true);
                foreach($staged as $file){$path=Uploads::path($file['storage_name']);if(!move_uploaded_file($file['tmp'],$path))throw new \RuntimeException('A poster could not be stored. No changes were saved.');$moved[]=$path;}
                $db->beginTransaction();
                $first=$kept[0] ?? $staged[0];$name=$first['storage_name'];$mime=$first['mime'];
                if($old)DB::query('UPDATE announcements SET title=?,interruption_date=?,image_path=?,image_mime=?,revision=revision+1,updated_at=UTC_TIMESTAMP(),interruption_date_source=? WHERE id=?',[$title,$date,$name,$mime,$source,$id]);
                else DB::query("INSERT INTO announcements(user_id,type,title,interruption_date,image_path,image_mime) VALUES (?,'scheduled',?,?,?,?)",[$userId,$title,$date,$name,$mime]);
                $saved=$id ?: (int)$db->lastInsertId();
                foreach($remove as $attachmentId)DB::query('UPDATE announcement_attachments SET removed_at=UTC_TIMESTAMP() WHERE id=? AND announcement_id=?',[$attachmentId,$saved]);
                $position=0;foreach($kept as $attachment)DB::query('UPDATE announcement_attachments SET sort_order=? WHERE id=?',[$position++,$attachment['id']]);
                foreach($kept as $poster)PosterOcr::save((int)$poster['id'],$results['saved:'.$poster['id']]);
                foreach($staged as $file){DB::query('INSERT INTO announcement_attachments(announcement_id,storage_name,mime,sort_order,content_hash) VALUES (?,?,?,?,?)',[$saved,$file['storage_name'],$file['mime'],$position++,$file['hash']]);PosterOcr::save((int)$db->lastInsertId(),$file['ocr']);}
                DB::query('UPDATE announcements SET interruption_date_source=? WHERE id=?',[$source,$saved]);
                DB::query("UPDATE announcements SET status=IF(status='failed' AND wordpress_post_id IS NULL,'draft',status) WHERE id=?",[$saved]);
                // Mirror the first active attachment for legacy readers; retain all media on their own rows.
                $primary=Attachments::all($saved)[0];
                DB::query('UPDATE announcements SET wordpress_media_id=?,wordpress_media_url=?,wordpress_media_hash=? WHERE id=?',[$primary['wordpress_media_id'],$primary['wordpress_media_url'],$primary['content_hash'],$saved]);
                Reporting::touch($saved);DB::audit($userId,'scheduled.posters_saved');$db->commit();return $saved;
            }catch(\Throwable $ex){if($db->inTransaction())$db->rollBack();foreach($moved as $path)if(is_file($path))unlink($path);throw $ex;}
        });
    }
}
