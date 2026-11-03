<?php
namespace App\Services;
use App\Models\DB;
final class YearTags {
    public static function resolve(WordPressClient $client,string $date): int {
        Announcement::dateTitle($date);$year=substr($date,0,4);
        return TagResolver::resolve($client,$year,$year,'wp_year_');
    }
    public static function apply(WordPressClient $client,array $a,?array $existing): array {
        $tagId=self::resolve($client,$a['interruption_date']);$tags=$existing['tags'] ?? [];
        $tags=array_values(array_filter($tags,fn($id)=>is_int($id)&&$id>0&&$id!==(int)$a['auto_year_tag_id']));$tags[]=$tagId;
        DB::query('UPDATE announcements SET pending_year_tag_id=?,pending_year=? WHERE id=?',[$tagId,(int)substr($a['interruption_date'],0,4),$a['id']]);return array_values(array_unique($tags));
    }
}
