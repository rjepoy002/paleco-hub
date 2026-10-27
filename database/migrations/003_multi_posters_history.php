<?php
use App\Models\DB;
use App\Services\Migrations;
return static function(): void {
    DB::query("CREATE TABLE IF NOT EXISTS announcement_attachments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        announcement_id BIGINT UNSIGNED NOT NULL,
        storage_name VARCHAR(100) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        sort_order INT UNSIGNED NOT NULL,
        content_hash CHAR(64) NULL,
        wordpress_media_id BIGINT UNSIGNED NULL,
        wordpress_media_url VARCHAR(1000) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        removed_at DATETIME NULL,
        FOREIGN KEY(announcement_id) REFERENCES announcements(id),
        UNIQUE KEY attachment_identity(announcement_id,storage_name),
        INDEX attachment_order(announcement_id,removed_at,sort_order,id)
    )");
    DB::query("INSERT IGNORE INTO announcement_attachments(announcement_id,storage_name,mime,sort_order,content_hash,wordpress_media_id,wordpress_media_url)
        SELECT id,image_path,image_mime,0,wordpress_media_hash,wordpress_media_id,wordpress_media_url FROM announcements WHERE type='scheduled' AND image_path IS NOT NULL");
    Migrations::column('announcements','history_hidden_at','DATETIME NULL');
    Migrations::column('announcements','history_hidden_by','BIGINT UNSIGNED NULL');
    Migrations::column('publishing_history','deleted_at','DATETIME NULL');
    Migrations::column('publishing_history','deleted_by','BIGINT UNSIGNED NULL');
};
