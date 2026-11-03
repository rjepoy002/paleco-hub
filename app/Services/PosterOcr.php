<?php
namespace App\Services;
use App\Models\DB;
final class PosterOcr {
    public function __construct(private ?VisionTransport $transport=null) {}
    public function scan(string $path): array {
        Uploads::validate($path);$hash=hash_file('sha256',$path);$provider=$this->transport?'mock':env('OCR_PROVIDER','none');
        $key=hash('sha256','dates-v1:'.$provider.':'.$hash);$lock='hub-ocr-'.substr($key,0,40);
        if((int)DB::query('SELECT GET_LOCK(?,25)',[$lock])->fetchColumn()!==1)return $this->unavailable($provider,$hash,'OCR is busy. Enter the date manually.');
        try{
            $cached=DB::query('SELECT result_json FROM ocr_cache WHERE cache_key=? AND expires_at>UTC_TIMESTAMP()',[$key])->fetchColumn();if($cached)return json_decode($cached,true,512,JSON_THROW_ON_ERROR);
            if(!$this->transport&&($provider!=='google_vision'||!env('GOOGLE_APPLICATION_CREDENTIALS')))$result=$this->unavailable($provider,$hash,'OCR is not configured. Read the poster and enter the interruption date manually.');
            else{try{$text=($this->transport ?? new GoogleVisionTransport())->text($path);$result=InterruptionDates::extract($text)+['provider'=>$provider,'hash'=>$hash];}catch(\Throwable $ex){$result=$this->unavailable($provider,$hash,'OCR is unavailable or quota-limited. Read this poster and enter the date manually.');}}
            $ttl=$result['status']==='unavailable'?60:31536000;
            DB::query('INSERT INTO ocr_cache(cache_key,result_json,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE result_json=VALUES(result_json),expires_at=VALUES(expires_at)',[$key,json_encode($result,JSON_THROW_ON_ERROR),$ttl]);return $result;
        }finally{DB::query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function unavailable(string $provider,string $hash,string $message): array{return ['status'=>'unavailable','date'=>null,'candidates'=>[],'confidence'=>0,'text'=>'','provider'=>$provider,'hash'=>$hash,'message'=>$message];}
    public static function save(int $id,array $r): void {
        DB::query('UPDATE announcement_attachments SET ocr_status=?,ocr_date=?,ocr_candidates=?,ocr_text=?,ocr_hash=?,ocr_provider=?,ocr_confidence=?,ocr_message=? WHERE id=?',[$r['status'],$r['date'],json_encode($r['candidates']),$r['text'],$r['hash'],$r['provider'],$r['confidence'],$r['message'],$id]);
    }
    public static function assertDate(array $results,string $date,string $source='manual'): void {
        Announcement::dateTitle($date);$valid=[];
        foreach($results as $r){if(in_array($r['status'],['conflict','weekday_mismatch'],true))throw new \RuntimeException('A poster has conflicting dates or an invalid weekday. Remove or correct that poster before sending to WordPress.');if($r['status']==='valid')$valid[]=$r['date'];}
        if(count(array_unique($valid))>1)throw new \RuntimeException('Posters show different interruption dates. Remove mismatched posters before sending to WordPress.');
        if($source==='ocr' && (count($valid)!==count($results)||!$valid||$valid[0]!==$date))throw new \RuntimeException('Not every poster has the same readable date. Enter the interruption date manually for unreadable posters, or correct conflicting selections.');
    }
    public static function validateAnnouncement(int $id,string $date): void {
        $results=[];foreach(Attachments::all($id) as $poster){
            $path=Uploads::path($poster['storage_name']);$hash=hash_file('sha256',$path);
            if($poster['ocr_hash']===$hash&&!in_array($poster['ocr_status'],['unscanned','unavailable'],true))$r=['status'=>$poster['ocr_status'],'date'=>$poster['ocr_date'],'candidates'=>json_decode($poster['ocr_candidates'] ?? '[]',true)];
            else{$r=(new self())->scan($path);self::save((int)$poster['id'],$r);}$results[]=$r;
        }$a=Publisher::find($id);self::assertDate($results,$date,$a['interruption_date_source'] ?? 'manual');
    }
}
