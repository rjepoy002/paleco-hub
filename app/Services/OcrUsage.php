<?php
namespace App\Services;
use PDO;
/** Conservative application-wide reservations, independent of publishing transactions. */
final class OcrUsage {
    public function __construct(private ?PDO $connection=null) {}
    private function db(): PDO {
        // A dedicated autocommit connection prevents a later draft/history rollback refunding a request.
        return $this->connection ??= new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.env('DB_DATABASE','paleco_hub').';charset=utf8mb4',env('DB_USERNAME','root'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    private function month(): string {
        // Database clock, fixed Asia/Manila UTC+08:00; no MySQL timezone-table installation required.
        return $this->db()->query("SELECT DATE_FORMAT(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+08:00'),'%Y-%m')")->fetchColumn();
    }
    public static function limit(): int {
        $raw=env('OCR_MONTHLY_LIMIT','900');
        if(!preg_match('/^\d{1,7}$/D',$raw)||(int)$raw>1000000)throw new OcrFailure('Invalid OCR_MONTHLY_LIMIT. Ask an administrator to set a non-negative application limit; enter the date manually.');
        return (int)$raw;
    }
    public static function units(array $payload): int {
        if(empty($payload['requests'])||!is_array($payload['requests'])||array_keys($payload)!==['requests'])throw new OcrFailure('Unsupported Vision payload; OCR was stopped before sending an unmetered request.');
        $units=0;
        foreach($payload['requests'] as $image){
            if(!is_array($image)||empty($image['image'])||empty($image['features'])||!is_array($image['features']))throw new OcrFailure('Invalid Vision image/features; OCR was stopped before sending.');
            foreach($image['features'] as $feature){
                // The current endpoint accepts single images, not multipage files. New features must be reviewed.
                if(!is_array($feature)||!in_array($feature['type'] ?? '',['DOCUMENT_TEXT_DETECTION','TEXT_DETECTION'],true))throw new OcrFailure('Unsupported Vision feature. Review its billing units before enabling it.');
                $units++;
            }
        }
        return $units;
    }
    public function reserve(array $payload): void {
        $units=self::units($payload);$limit=self::limit();
        try{
            $db=$this->db();
            if($db->inTransaction())throw new \RuntimeException('Reservation must autocommit.');
            $month=$this->month();
            $insert=$db->prepare('INSERT IGNORE INTO ocr_monthly_usage(usage_month) VALUES (?)');$insert->execute([$month]);
            // One conditional, autocommitted UPDATE serializes concurrent contenders on the month row.
            $update=$db->prepare('UPDATE ocr_monthly_usage SET units_reserved=units_reserved+? WHERE usage_month=? AND units_reserved<=?');
            if($units>$limit)throw new OcrFailure('Monthly OCR allowance reached. Enter the interruption date manually; new requests resume next month (Asia/Manila).');
            $update->execute([$units,$month,$limit-$units]);
            if($update->rowCount()!==1)throw new OcrFailure('Monthly OCR allowance reached. Enter the interruption date manually; new requests resume next month (Asia/Manila).');
        }catch(OcrFailure $ex){throw $ex;}
        catch(\Throwable $ex){throw new OcrFailure('OCR usage tracking is unavailable. Ask an administrator to check the database/migrations; enter the date manually. No Vision request was sent.');}
    }
    public function status(): array {
        try{
            $limit=self::limit();$month=$this->month();$query=$this->db()->prepare('SELECT units_reserved FROM ocr_monthly_usage WHERE usage_month=?');$query->execute([$month]);$used=(int)($query->fetchColumn() ?: 0);
            return ['available'=>true,'month'=>$month,'timezone'=>'Asia/Manila','used'=>$used,'limit'=>$limit,'remaining'=>max(0,$limit-$used)];
        }catch(\Throwable $ex){return ['available'=>false,'message'=>'OCR usage tracking is unavailable. Enter the date manually and ask an administrator to check configuration/migrations.'];}
    }
}
