<?php
namespace App\Services;
use App\Models\DB;
final class Drafts {
    public static function save(string $type,int $userId,array $input,?array $file): int {
        Publisher::authorize($userId);
        if (!in_array($type,['partial','scheduled'],true)) throw new \RuntimeException('Invalid announcement module.');
        $id=(int)($input['id'] ?? 0);
        return AnnouncementLock::run($id,function() use($type,$userId,$input,$file,$id) {
            $old=$id?Publisher::find($id):null;
            if ($old && ($old['type']!==$type || $old['uncertain_stage'] || $old['status']==='publishing')) throw new \RuntimeException('Reconcile the pending WordPress result before editing this announcement.');
            if ($old && (int)($input['revision'] ?? 0)!==(int)$old['revision']) throw new \RuntimeException('Someone changed this announcement. Reload before saving your edits.');
            $body=(string)($input['body'] ?? ''); $title=(string)($input['title'] ?? ''); $date=null; $newImage=null;
            $image=$old['image_path'] ?? null; $mime=$old['image_mime'] ?? null;
            if ($type==='partial') {
                if (!trim($body) || mb_strlen($body)>100000) throw new \RuntimeException('Enter an announcement up to 100,000 characters.');
                if (!trim($title)) $title=Announcement::title($body);
            } else {
                $date=(string)($input['date'] ?? ''); $fallback=Announcement::dateTitle($date);
                if (!isset($input['date_confirmed'])) throw new \RuntimeException('Confirm the interruption date shown on the poster.');
                if (!trim($title)) $title=$fallback;
                if ($file && $file['error']!==UPLOAD_ERR_NO_FILE) {
                    if ($file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new \RuntimeException('The poster upload did not complete. Select the image again.');
                    $mime=Uploads::validate($file['tmp_name']);
                    $dates=(new UnconfiguredRecognition())->dates($file['tmp_name']);
                    if (count($dates)>1 && !in_array($date,$dates,true)) throw new \RuntimeException('Confirm one of the detected interruption dates.');
                    $newImage=bin2hex(random_bytes(24)); $image=$newImage;
                } elseif (!$image) throw new \RuntimeException('Select a JPG, PNG, or WebP poster.');
            }
            if (!trim($title) || mb_strlen($title)>255) throw new \RuntimeException('Enter a title up to 255 characters.');
            if ($newImage) {
                if (!is_dir(ROOT.'/storage/uploads')) mkdir(ROOT.'/storage/uploads',0700,true);
                if (!move_uploaded_file($file['tmp_name'],Uploads::path($newImage))) throw new \RuntimeException('The poster could not be stored.');
            }
            $db=DB::connection(); $db->beginTransaction();
            try {
                if ($old) DB::query('UPDATE announcements SET title=?,body=?,image_path=?,image_mime=?,interruption_date=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=?',[$title,$body,$image,$mime,$date,$id]);
                else DB::query('INSERT INTO announcements (user_id,type,title,body,image_path,image_mime,interruption_date) VALUES (?,?,?,?,?,?,?)',[$userId,$type,$title,$body,$image,$mime,$date]);
                $savedId=$id ?: (int)$db->lastInsertId();
                DB::audit($userId,$old?'announcement.draft_edited':'announcement.draft_saved'); $db->commit();
            } catch (\Throwable $ex) { $db->rollBack(); if ($newImage) unlink(Uploads::path($newImage)); throw $ex; }
            if ($newImage && $old && $old['image_path'] && is_file(Uploads::path($old['image_path']))) unlink(Uploads::path($old['image_path']));
            return $savedId;
        });
    }
}
