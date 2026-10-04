<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * endorse_post_daily: one narrow row per post per day, clustered by (campaign, date),
 * read by the /endorse analytics endpoints instead of aggregating endorse_logs per request.
 * See docs/devplan-endorse-post-daily.md.
 *
 * endorse_logs stays the record. Triggers recompute the affected (post, day) row from
 * endorse_logs on every write, so the sync worker and every other writer stay untouched.
 * Rows only exist for posts that exist in endorse (same as the old INNER JOIN endorse).
 */
class Migration_Create_endorse_post_daily extends CI_Migration
{
    private $triggers = [
        'trg_endorse_logs_post_daily_ai', 'trg_endorse_logs_post_daily_au', 'trg_endorse_logs_post_daily_ad',
        'trg_endorse_post_daily_ai', 'trg_endorse_post_daily_au', 'trg_endorse_post_daily_ad',
    ];

    public function up()
    {
        $v = $this->db->query("SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust")->row_array();
        if (intval($v['log_bin']) === 1 && intval($v['trust']) !== 1) {
            throw new RuntimeException('endorse_post_daily needs triggers: run '
                . '"SET PERSIST log_bin_trust_function_creators = 1;" as a MySQL admin, then migrate again.');
        }

        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_post_daily (
                id_campaign INT NOT NULL,
                log_date DATE NOT NULL,
                id_endorse INT NOT NULL,
                views_before INT NOT NULL,
                views_after INT NOT NULL,
                views_gain INT NOT NULL,
                likes_gain INT NOT NULL,
                comment_gain INT NOT NULL,
                share_save_gain INT NOT NULL,
                PRIMARY KEY (id_campaign, log_date, id_endorse),
                KEY idx_epd_endorse_date (id_endorse, log_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->drop_triggers();
        // Same expressions the endpoints used on endorse_logs grouped by (post, DATE(date)).
        $select = "SELECT e.id_campaign, el.log_date, el.id_endorse,
                          MAX(el.views_before), MAX(el.views_after),
                          SUM(el.views_after - el.views_before), SUM(el.likes_after - el.likes_before),
                          SUM(el.comment_after - el.comment_before), SUM(el.share_save_after - el.share_save_before)
                     FROM endorse_logs el JOIN endorse e ON e.id = el.id_endorse";
        $group = "GROUP BY e.id_campaign, el.log_date, el.id_endorse";
        $refresh = function ($row) use ($select, $group) {
            return "DELETE FROM endorse_post_daily WHERE id_endorse = {$row}.id_endorse AND log_date = {$row}.log_date;
                    INSERT INTO endorse_post_daily {$select}
                     WHERE el.id_endorse = {$row}.id_endorse AND el.log_date = {$row}.log_date {$group};";
        };

        $this->db->query("CREATE TRIGGER trg_endorse_logs_post_daily_ai AFTER INSERT ON endorse_logs FOR EACH ROW
            BEGIN {$refresh('NEW')} END");
        $this->db->query("CREATE TRIGGER trg_endorse_logs_post_daily_au AFTER UPDATE ON endorse_logs FOR EACH ROW
            BEGIN
                {$refresh('NEW')}
                IF NOT (OLD.id_endorse <=> NEW.id_endorse AND OLD.log_date <=> NEW.log_date) THEN {$refresh('OLD')} END IF;
            END");
        $this->db->query("CREATE TRIGGER trg_endorse_logs_post_daily_ad AFTER DELETE ON endorse_logs FOR EACH ROW
            BEGIN {$refresh('OLD')} END");
        // Logs written before their endorse row existed get picked up when it appears.
        $this->db->query("CREATE TRIGGER trg_endorse_post_daily_ai AFTER INSERT ON endorse FOR EACH ROW
            BEGIN INSERT IGNORE INTO endorse_post_daily {$select} WHERE el.id_endorse = NEW.id {$group}; END");
        $this->db->query("CREATE TRIGGER trg_endorse_post_daily_au AFTER UPDATE ON endorse FOR EACH ROW
            BEGIN
                IF NOT (OLD.id_campaign <=> NEW.id_campaign) THEN
                    UPDATE endorse_post_daily SET id_campaign = NEW.id_campaign WHERE id_endorse = NEW.id;
                END IF;
            END");
        $this->db->query("CREATE TRIGGER trg_endorse_post_daily_ad AFTER DELETE ON endorse FOR EACH ROW
            BEGIN DELETE FROM endorse_post_daily WHERE id_endorse = OLD.id; END");

        // Backfill after the triggers exist: rows they already wrote are newer, so IGNORE keeps them.
        // Chunked by post so each (post, day) group is complete and row locks stay short (~2-3 s per chunk).
        $r = $this->db->query("SELECT MIN(id) AS lo, MAX(id) AS hi FROM endorse")->row_array();
        for ($lo = intval($r['lo']); $lo <= intval($r['hi']); $lo += 500) {
            $hi = $lo + 499;
            $this->db->query("INSERT IGNORE INTO endorse_post_daily {$select}
                WHERE el.id_endorse BETWEEN {$lo} AND {$hi} AND el.log_date IS NOT NULL {$group}");
        }
    }

    public function down()
    {
        $this->drop_triggers();
        $this->db->query('DROP TABLE IF EXISTS endorse_post_daily');
    }

    private function drop_triggers()
    {
        foreach ($this->triggers as $t) {
            $this->db->query("DROP TRIGGER IF EXISTS {$t}");
        }
    }
}
