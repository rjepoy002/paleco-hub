<?php
namespace App\Services;
use App\Models\DB;
final class AnnouncementLock {
    public static function run(int $id,callable $work): mixed {
        $name='hub-ann-'.substr(hash('sha256',env('DB_DATABASE').':'.$id),0,40);
        if ((int)DB::query('SELECT GET_LOCK(?,0)',[$name])->fetchColumn()!==1) throw new \RuntimeException('This announcement is being processed. Refresh its status before trying again.');
        try { return $work(); } finally { DB::query('SELECT RELEASE_LOCK(?)',[$name]); }
    }
}
