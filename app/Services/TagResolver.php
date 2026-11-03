<?php
namespace App\Services;
use App\Models\DB;
/** Exact-name term resolution with durable uncertainty protection. */
final class TagResolver {
    public static function resolve(WordPressClient $client,string $year,string $slug,string $namespace): int {
        $key=$namespace.hash('sha256',rtrim($client->connection['rest_url'],'/').':'.$slug);$lock='hub-tag-'.substr(hash('sha256',$key),0,40);
        if((int)DB::query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)throw new WordPressFailure('A WordPress tag is being resolved. Retry this announcement shortly.');
        try{
            $lookup=function()use($client,$year,$slug):array{
                $matches=$client->request('GET','/tags?slug='.rawurlencode($slug).'&hide_empty=false&per_page=100');
                if($matches)return $matches;
                $exact=[];
                for($page=1;$page<=10;$page++){$items=$client->request('GET','/tags?search='.rawurlencode($year).'&hide_empty=false&per_page=100&page='.$page);foreach($items as $item)if(($item['name'] ?? '')===$year)$exact[]=$item;if(count($items)<100)break;}
                return $exact;
            };
            $remember=function(array $tag)use($key,$year):int{$id=self::verify($tag,$year);DB::query('INSERT INTO application_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,json_encode(['pending'=>false,'id'=>$id])]);return $id;};
            $matches=$lookup();
            if(count($matches)>1)throw new WordPressFailure('The WordPress tag is ambiguous. Ask an administrator to verify WordPress tags.');
            if($matches)return $remember($matches[0]);
            $saved=DB::query('SELECT setting_value FROM application_settings WHERE setting_key=?',[$key])->fetchColumn();
            if($saved && json_decode($saved,true)['pending'])throw new WordPressFailure('An earlier WordPress tag creation is uncertain. Retry reconciliation later; no duplicate tag was created.',true);
            DB::query('INSERT INTO application_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,json_encode(['pending'=>true])]);
            try{$result=$client->request('POST','/tags',['name'=>$year,'slug'=>$slug]);return $remember($result);}
            catch(WordPressFailure $ex){
                // WordPress enforces unique terms. A concurrent/accepted request can be recovered by exact slug.
                if(!$ex->uncertain)DB::query('DELETE FROM application_settings WHERE setting_key=?',[$key]);
                $matches=$lookup();if(count($matches)===1)return $remember($matches[0]);throw $ex;
            }
        }finally{DB::query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private static function verify(array $tag,string $year): int {
        if(!is_int($tag['id'] ?? null)||$tag['id']<1||empty($tag['slug'])||($tag['name'] ?? '')!==$year)throw new WordPressFailure('WordPress returned an invalid WordPress tag identity. Reconcile tags before retrying.',true);
        return $tag['id'];
    }
}
