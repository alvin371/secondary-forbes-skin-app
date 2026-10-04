<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Snapshot tables for the /endorse campaign detail page (see Endorse_campaign_snapshot).
 *
 * endorse_campaign_snapshot  one row per campaign: dirty marker + build state
 * endorse_campaign_daily     one row per campaign per day: V2 bucket + legacy chart sums
 *
 * Triggers on endorse_logs and endorse mark the campaign dirty on every write,
 * so all ~15 writers (sync, cron, edit stats, repair, transfer, delete) are
 * covered without touching them. Each trigger is one upsert of a single row.
 *
 * With binary logging on, CREATE TRIGGER needs SUPER unless
 * log_bin_trust_function_creators=1 (set once by a DB admin):
 *   SET PERSIST log_bin_trust_function_creators = 1;
 */
class Migration_Create_endorse_campaign_snapshot extends CI_Migration
{
    private $triggers = [
        'trg_endorse_logs_snapshot_ai', 'trg_endorse_logs_snapshot_au', 'trg_endorse_logs_snapshot_ad',
        'trg_endorse_snapshot_ai', 'trg_endorse_snapshot_au', 'trg_endorse_snapshot_ad',
    ];

    public function up()
    {
        $v = $this->db->query("SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust")->row_array();
        if (intval($v['log_bin']) === 1 && intval($v['trust']) !== 1) {
            throw new RuntimeException('endorse_campaign_snapshot needs triggers: run '
                . '"SET PERSIST log_bin_trust_function_creators = 1;" as a MySQL admin, then migrate again.');
        }

        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_campaign_snapshot (
                id_campaign INT NOT NULL PRIMARY KEY,
                dirty_at DATETIME(6) NOT NULL,
                built_at DATETIME(6) NULL,
                built_from DATE NULL,
                built_until DATE NULL,
                calculation_version VARCHAR(16) NULL,
                dup_groups INT NOT NULL DEFAULT 0,
                dup_rows INT NOT NULL DEFAULT 0,
                pre_from JSON NULL,
                build_ms INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_campaign_daily (
                id_campaign INT NOT NULL,
                log_date DATE NOT NULL,
                v2_bucket JSON NULL,
                v2_observasi INT NOT NULL DEFAULT 0,
                views BIGINT NOT NULL DEFAULT 0,
                likes BIGINT NOT NULL DEFAULT 0,
                comment BIGINT NOT NULL DEFAULT 0,
                share_save BIGINT NOT NULL DEFAULT 0,
                cost DOUBLE NULL,
                endorse INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id_campaign, log_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->drop_triggers();
        $mark = function ($expr) {
            return "INSERT INTO endorse_campaign_snapshot (id_campaign, dirty_at) VALUES ({$expr}, NOW(6))
                    ON DUPLICATE KEY UPDATE dirty_at = NOW(6);";
        };
        // A log belongs to its endorse's current campaign (that is what the page joins on).
        $logCampaign = function ($row) {
            return "COALESCE((SELECT id_campaign FROM endorse WHERE id = {$row}.id_endorse), {$row}.id_campaign)";
        };

        $this->db->query("CREATE TRIGGER trg_endorse_logs_snapshot_ai AFTER INSERT ON endorse_logs FOR EACH ROW
            BEGIN
                DECLARE c INT;
                SET c = {$logCampaign('NEW')};
                IF c IS NOT NULL THEN {$mark('c')} END IF;
            END");
        $this->db->query("CREATE TRIGGER trg_endorse_logs_snapshot_au AFTER UPDATE ON endorse_logs FOR EACH ROW
            BEGIN
                DECLARE c INT;
                DECLARE o INT;
                SET c = {$logCampaign('NEW')};
                SET o = {$logCampaign('OLD')};
                IF c IS NOT NULL THEN {$mark('c')} END IF;
                IF o IS NOT NULL AND NOT (o <=> c) THEN {$mark('o')} END IF;
            END");
        $this->db->query("CREATE TRIGGER trg_endorse_logs_snapshot_ad AFTER DELETE ON endorse_logs FOR EACH ROW
            BEGIN
                DECLARE o INT;
                SET o = {$logCampaign('OLD')};
                IF o IS NOT NULL THEN {$mark('o')} END IF;
            END");
        // endorse rows define the campaign's post scope (status, link, posting date, PIC, campaign).
        $this->db->query("CREATE TRIGGER trg_endorse_snapshot_ai AFTER INSERT ON endorse FOR EACH ROW
            BEGIN {$mark('NEW.id_campaign')} END");
        $this->db->query("CREATE TRIGGER trg_endorse_snapshot_au AFTER UPDATE ON endorse FOR EACH ROW
            BEGIN
                {$mark('NEW.id_campaign')}
                IF OLD.id_campaign <> NEW.id_campaign THEN {$mark('OLD.id_campaign')} END IF;
            END");
        $this->db->query("CREATE TRIGGER trg_endorse_snapshot_ad AFTER DELETE ON endorse FOR EACH ROW
            BEGIN {$mark('OLD.id_campaign')} END");

        // Every campaign starts dirty; api/cronjob/endorse-snapshot builds them.
        $this->db->query("INSERT IGNORE INTO endorse_campaign_snapshot (id_campaign, dirty_at)
            SELECT id, NOW(6) FROM endorse_campaign");
    }

    public function down()
    {
        $this->drop_triggers();
        $this->db->query('DROP TABLE IF EXISTS endorse_campaign_daily');
        $this->db->query('DROP TABLE IF EXISTS endorse_campaign_snapshot');
    }

    private function drop_triggers()
    {
        foreach ($this->triggers as $t) {
            $this->db->query("DROP TRIGGER IF EXISTS {$t}");
        }
    }
}
