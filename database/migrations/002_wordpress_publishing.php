<?php
use App\Services\Migrations;
use App\Models\DB;
return static function (): void {
    foreach ([
        'revision'=>'INT UNSIGNED NOT NULL DEFAULT 1',
        'wordpress_post_id'=>'BIGINT UNSIGNED NULL',
        'wordpress_post_url'=>'VARCHAR(1000) NULL',
        'wordpress_status'=>'VARCHAR(30) NULL',
        'wordpress_destination'=>'VARCHAR(500) NULL',
        'wordpress_media_id'=>'BIGINT UNSIGNED NULL',
        'wordpress_media_url'=>'VARCHAR(1000) NULL',
        'wordpress_media_hash'=>'CHAR(64) NULL',
        'remote_key'=>'VARCHAR(80) NULL',
        'synced_revision'=>'INT UNSIGNED NULL',
        'published_at'=>'DATETIME NULL',
        'wordpress_saved_at'=>'DATETIME NULL',
        'last_publish_attempt_at'=>'DATETIME NULL',
        'publish_error'=>'VARCHAR(500) NULL',
        'uncertain_stage'=>'VARCHAR(20) NULL',
        'pending_payload'=>'MEDIUMTEXT NULL',
        'updated_at'=>'DATETIME NULL'
    ] as $name=>$definition) Migrations::column('announcements',$name,$definition);
    DB::query("CREATE TABLE IF NOT EXISTS publishing_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        announcement_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(30) NOT NULL,
        revision INT UNSIGNED NOT NULL,
        outcome VARCHAR(30) NOT NULL DEFAULT 'publishing',
        wordpress_post_id BIGINT UNSIGNED NULL,
        message VARCHAR(500) NULL,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        FOREIGN KEY (announcement_id) REFERENCES announcements(id),
        FOREIGN KEY (user_id) REFERENCES users(id),
        INDEX announcement_attempts (announcement_id,id)
    )");
};
