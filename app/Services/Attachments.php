<?php
namespace App\Services;
use App\Models\DB;
final class Attachments {
    public static function limit(): int { return max(1,min(30,(int)(ini_get('max_file_uploads') ?: 20),(int)env('MAX_POSTERS','10'))); }
    public static function all(int $id): array {
        // Older callers/tests can still create a single-poster announcement using the original columns.
        if(!DB::query('SELECT 1 FROM announcement_attachments WHERE announcement_id=? LIMIT 1',[$id])->fetchColumn()) {
            DB::query("INSERT IGNORE INTO announcement_attachments(announcement_id,storage_name,mime,sort_order,content_hash,wordpress_media_id,wordpress_media_url) SELECT id,image_path,image_mime,0,wordpress_media_hash,wordpress_media_id,wordpress_media_url FROM announcements WHERE id=? AND type='scheduled' AND image_path IS NOT NULL",[$id]);
        }
        return DB::query('SELECT * FROM announcement_attachments WHERE announcement_id=? AND removed_at IS NULL ORDER BY sort_order,id',[$id])->fetchAll();
    }
    public static function files(?array $upload): array {
        if(!$upload)return [];
        if(!is_array($upload['error'] ?? null))return ($upload['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE?[]:[$upload];
        $files=[];
        foreach($upload['error'] as $index=>$error) {
            if($error===UPLOAD_ERR_NO_FILE)continue;
            $files[]=['error'=>$error,'tmp_name'=>$upload['tmp_name'][$index] ?? '','size'=>$upload['size'][$index] ?? 0];
        }
        return $files;
    }
    public static function saveMedia(int $announcementId,int $attachmentId,array $media,string $hash): void {
        DB::query('UPDATE announcement_attachments SET wordpress_media_id=?,wordpress_media_url=?,content_hash=? WHERE id=? AND announcement_id=? AND removed_at IS NULL',[$media['id'],$media['url'],$hash,$attachmentId,$announcementId]);
        $first=self::all($announcementId)[0] ?? null;
        if($first && (int)$first['id']===$attachmentId) DB::query('UPDATE announcements SET wordpress_media_id=?,wordpress_media_url=?,wordpress_media_hash=? WHERE id=?',[$media['id'],$media['url'],$hash,$announcementId]);
        DB::query('UPDATE announcements SET uncertain_stage=NULL,pending_payload=NULL WHERE id=?',[$announcementId]);
    }
}
