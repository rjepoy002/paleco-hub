<?php
use App\Models\DB;
return static function(): void {
    DB::query("CREATE TABLE IF NOT EXISTS ocr_monthly_usage (usage_month CHAR(7) PRIMARY KEY, units_reserved BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
};
