<?php
use App\Models\DB;
use App\Services\Migrations;
return static function(): void {
    foreach(['ocr_status'=>"VARCHAR(30) NOT NULL DEFAULT 'unscanned'",'ocr_date'=>'DATE NULL','ocr_candidates'=>'TEXT NULL','ocr_text'=>'MEDIUMTEXT NULL','ocr_hash'=>'CHAR(64) NULL','ocr_provider'=>'VARCHAR(40) NULL','ocr_confidence'=>'DECIMAL(5,4) NULL','ocr_message'=>'VARCHAR(500) NULL'] as $name=>$definition)Migrations::column('announcement_attachments',$name,$definition);
    foreach(['reporting_epoch'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0','report_activity_at'=>'DATETIME(6) NULL','report_state'=>'VARCHAR(30) NULL','auto_year_tag_id'=>'BIGINT UNSIGNED NULL','auto_year'=>'SMALLINT UNSIGNED NULL','pending_year_tag_id'=>'BIGINT UNSIGNED NULL','pending_year'=>'SMALLINT UNSIGNED NULL'] as $name=>$definition)Migrations::column('announcements',$name,$definition);
    DB::query("CREATE TABLE IF NOT EXISTS ocr_cache(cache_key CHAR(64) PRIMARY KEY,result_json MEDIUMTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,expires_at DATETIME NOT NULL)");
    DB::query("INSERT IGNORE INTO application_settings(setting_key,setting_value) VALUES ('dashboard_reporting_epoch','0'),('dashboard_history_reset_at','')");
    Migrations::column('announcements','interruption_date_source',"VARCHAR(20) NOT NULL DEFAULT 'manual'");
    DB::query("UPDATE announcements SET report_activity_at=COALESCE(updated_at,last_publish_attempt_at,created_at),report_state=CASE WHEN status IN ('failed','publishing') OR uncertain_stage IS NOT NULL THEN 'failed_pending' WHEN wordpress_status='publish' AND synced_revision=revision THEN 'published' WHEN wordpress_status='draft' AND synced_revision=revision THEN 'wordpress_draft' ELSE 'local_draft' END WHERE report_activity_at IS NULL");
};
