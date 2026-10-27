<?php
namespace App\Services;
use App\Models\DB;
final class Migrations {
    public static function run(): void {
        $lock='hub-migrate-'.substr(hash('sha256',env('DB_DATABASE')),0,32);
        if ((int)DB::query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1) throw new \RuntimeException('Another migration is running.');
        try {
            DB::query('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
            foreach (glob(ROOT.'/database/migrations/*.php') as $file) {
                $version=basename($file,'.php');
                if (DB::query('SELECT 1 FROM schema_migrations WHERE version=?',[$version])->fetchColumn()) continue;
                (require $file)();
                DB::query('INSERT INTO schema_migrations (version) VALUES (?)',[$version]);
            }
        } finally { DB::query('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    public static function column(string $table,string $name,string $definition): void {
        if (!DB::query('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$name])->fetchColumn()) DB::query("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
    }
}
