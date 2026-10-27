<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (!is_file(ROOT.'/.env')) { $example=file_get_contents(ROOT.'/.env.example'); $example=str_replace('APP_KEY=','APP_KEY='.base64_encode(random_bytes(32)),$example); file_put_contents(ROOT.'/.env',$example); echo ".env created with an independent encryption key.\n"; }
if (($argv[1] ?? '')==='--init-only') exit;
Dotenv\Dotenv::createImmutable(ROOT)->safeLoad();
$database=env('DB_DATABASE','paleco_hub'); if (!preg_match('/^[a-zA-Z0-9_]+$/',$database)) throw new RuntimeException('Invalid database name');
$pdo=new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';charset=utf8mb4',env('DB_USERNAME','root'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $pdo->exec('USE `'.$database.'`');
foreach (explode(';',file_get_contents(ROOT.'/database/schema.sql')) as $sql) if (trim($sql)!=='') $pdo->exec($sql);
echo "Database schema installed. No user accounts have been created.\n";
App\Services\Migrations::run();
echo "Incremental migrations applied.\n";
