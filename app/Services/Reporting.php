<?php
namespace App\Services;
use App\Models\DB;
final class Reporting {
    public static function period(): array {
        return ['epoch'=>(int)DB::query("SELECT setting_value FROM application_settings WHERE setting_key='dashboard_reporting_epoch'")->fetchColumn(),'reset_at'=>DB::query("SELECT setting_value FROM application_settings WHERE setting_key='dashboard_history_reset_at'")->fetchColumn() ?: null];
    }
    public static function touch(int $id): void {
        $db=DB::connection();$own=!$db->inTransaction();if($own)$db->beginTransaction();
        try {
            $a=DB::query('SELECT * FROM announcements WHERE id=? FOR UPDATE',[$id])->fetch();
            if(!$a)throw new \RuntimeException('Reporting announcement not found.');
            $epoch=(int)DB::query("SELECT setting_value FROM application_settings WHERE setting_key='dashboard_reporting_epoch' FOR UPDATE")->fetchColumn();
            $state=($a['uncertain_stage'] || in_array($a['status'],['failed','publishing'],true))?'failed_pending':(((int)$a['synced_revision']===(int)$a['revision'] && $a['wordpress_status']==='publish')?'published':(((int)$a['synced_revision']===(int)$a['revision'] && $a['wordpress_status']==='draft')?'wordpress_draft':'local_draft'));
            DB::query('UPDATE announcements SET reporting_epoch=?,report_activity_at=UTC_TIMESTAMP(6),report_state=?,history_hidden_at=NULL,history_hidden_by=NULL WHERE id=?',[$epoch,$state,$id]);
            if($own)$db->commit();
        }catch(\Throwable $ex){if($own&&$db->inTransaction())$db->rollBack();throw $ex;}
    }
    public static function reset(): void {
        if(!DB::connection()->inTransaction())throw new \LogicException('History reset must share its deletion transaction.');
        $epoch=(int)DB::query("SELECT setting_value FROM application_settings WHERE setting_key='dashboard_reporting_epoch' FOR UPDATE")->fetchColumn();
        DB::query("UPDATE application_settings SET setting_value=? WHERE setting_key='dashboard_reporting_epoch'",[(string)($epoch+1)]);
        DB::query("UPDATE application_settings SET setting_value=UTC_TIMESTAMP(6) WHERE setting_key='dashboard_history_reset_at'");
    }
    public static function stats(): array {
        $epoch=self::period()['epoch'];
        return DB::query("SELECT COUNT(*) total,COALESCE(SUM(report_state='published'),0) published,COALESCE(SUM(report_state='wordpress_draft'),0) wordpress_drafts,COALESCE(SUM(report_state='local_draft'),0) local_drafts,COALESCE(SUM(report_state='failed_pending'),0) failed_pending FROM announcements WHERE reporting_epoch=? AND report_activity_at IS NOT NULL",[$epoch])->fetch();
    }
    public static function recent(): array {
        return DB::query('SELECT a.*,u.name author FROM announcements a JOIN users u ON u.id=a.user_id WHERE a.reporting_epoch=? AND a.report_activity_at IS NOT NULL ORDER BY a.report_activity_at DESC,a.id DESC LIMIT 5',[self::period()['epoch']])->fetchAll();
    }
}
