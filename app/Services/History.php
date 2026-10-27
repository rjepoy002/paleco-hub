<?php
namespace App\Services;
use App\Models\DB;
final class History {
    public static function snapshot(bool $lock=false): array {
        $suffix=$lock?' FOR UPDATE':'';
        $ann=array_map('intval',DB::query('SELECT id FROM announcements WHERE history_hidden_at IS NULL ORDER BY id'.$suffix)->fetchAll(\PDO::FETCH_COLUMN));
        $events=array_map('intval',DB::query('SELECT id FROM publishing_history WHERE deleted_at IS NULL ORDER BY id'.$suffix)->fetchAll(\PDO::FETCH_COLUMN));
        return ['announcements'=>$ann,'events'=>$events,'count'=>count($ann)+count($events),'token'=>hash_hmac('sha256',json_encode([$ann,$events]),env('APP_KEY'))];
    }
    public static function canDelete(array $user,int $owner): bool { return (int)$user['role_id']===1 || ((int)$user['role_id']===2 && (int)$user['id']===$owner); }
    public static function remove(int $userId,string $kind,int $id,bool $confirmed): int {
        Publisher::authorize($userId);if(!$confirmed)throw new \RuntimeException('Confirm deletion before removing local history.');
        $tables=['announcement'=>['announcements','history_hidden_at','history_hidden_by'],'event'=>['publishing_history','deleted_at','deleted_by']];
        if(!isset($tables[$kind]) || $id<1)throw new \RuntimeException('Invalid history record.');
        [$table,$date,$actor]=$tables[$kind];$db=DB::connection();$db->beginTransaction();
        try{
            $user=DB::query('SELECT id,role_id FROM users WHERE id=? AND is_active=1',[$userId])->fetch();
            $row=DB::query("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id])->fetch();
            if(!$row || !self::canDelete($user,(int)$row['user_id']))throw new \RuntimeException('You may delete only your own history records. Administrators may delete any local history.');
            if($row[$date]){$db->commit();return 0;}
            DB::query("UPDATE $table SET $date=UTC_TIMESTAMP(),$actor=? WHERE id=?",[$userId,$id]);
            DB::audit($userId,'history.hidden.'.$kind.'.'.$id);$db->commit();return 1;
        }catch(\Throwable $ex){$db->rollBack();throw $ex;}
    }
    public static function clear(int $userId,string $token,bool $confirmed): int {
        $db=DB::connection();$db->beginTransaction();
        try{
            $user=DB::query('SELECT role_id FROM users WHERE id=? AND is_active=1',[$userId])->fetch();
            if(!$user || (int)$user['role_id']!==1)throw new \RuntimeException('Only Administrators may clear publishing history.');
            if(!$confirmed)throw new \RuntimeException('Explicit confirmation is required to clear local history.');
            $snapshot=self::snapshot(true);
            if(!hash_equals($snapshot['token'],$token))throw new \RuntimeException('The history count changed. Refresh and confirm the current records before clearing.');
            DB::query('UPDATE announcements SET history_hidden_at=UTC_TIMESTAMP(),history_hidden_by=? WHERE history_hidden_at IS NULL',[$userId]);
            DB::query('UPDATE publishing_history SET deleted_at=UTC_TIMESTAMP(),deleted_by=? WHERE deleted_at IS NULL',[$userId]);
            DB::audit($userId,'history.cleared.'.$snapshot['count']);$db->commit();return $snapshot['count'];
        }catch(\Throwable $ex){$db->rollBack();throw $ex;}
    }
}
