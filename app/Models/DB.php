<?php
namespace App\Models;
use PDO;
final class DB {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        return self::$pdo ??= new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.env('DB_DATABASE','paleco_hub').';charset=utf8mb4', env('DB_USERNAME','root'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone = '+00:00'"]);
    }
    public static function query(string $sql,array $args=[]): \PDOStatement { $s=self::connection()->prepare($sql); $s->execute($args); return $s; }
    public static function audit(?int $user,string $action): void { self::query('INSERT INTO audit_logs (user_id,action) VALUES (?,?)',[$user,$action]); }
}
