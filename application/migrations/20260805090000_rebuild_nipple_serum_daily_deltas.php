<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Rebuild_nipple_serum_daily_deltas extends CI_Migration
{
    private const CAMPAIGN_ID = 27;
    private const FROM_DATE = '2026-08-01';
    private const UNTIL_DATE = '2026-08-03';

    public function up()
    {
        if (!$this->db->table_exists('endorse_logs')) {
            return;
        }

        $this->load->library('endorse_sync');
        $processed = $this->endorse_sync->rebuild_daily_deltas(
            self::CAMPAIGN_ID,
            self::FROM_DATE,
            self::UNTIL_DATE,
            0
        );
        $this->rebuild_rollup();

        log_message('info', 'Rebuilt ' . $processed . ' daily endorse logs for Internal Nipple Serum.');
    }

    public function down()
    {
        // This repair recomputes historical values from persisted snapshots and
        // is intentionally irreversible.
    }

    private function rebuild_rollup(): void
    {
        if (!$this->db->table_exists('endorse_logs_daily_rollup')
            || !$this->db->field_exists('log_date', 'endorse_logs')) {
            return;
        }

        $campaign = self::CAMPAIGN_ID;
        $from = $this->db->escape(self::FROM_DATE);
        $until = $this->db->escape(self::UNTIL_DATE);

        $this->db->query("
            DELETE r
            FROM endorse_logs_daily_rollup r
            INNER JOIN endorse_logs l
                ON l.id_endorse = r.id_endorse AND l.log_date = r.log_date
            WHERE l.id_campaign = '$campaign'
              AND l.log_date BETWEEN $from AND $until
        ");

        $result = $this->db->query("
            INSERT INTO endorse_logs_daily_rollup
                (id_endorse, log_date, likes_delta, comment_delta, share_save_delta, views_delta,
                 likes_after, comment_after, share_save_after, views_after, total_cost, last_updated)
            SELECT
                l.id_endorse,
                l.log_date,
                SUM(GREATEST(COALESCE(l.likes, 0), 0)),
                SUM(GREATEST(COALESCE(l.comment, 0), 0)),
                SUM(GREATEST(COALESCE(l.share_save, 0), 0)),
                SUM(GREATEST(COALESCE(l.views, 0), 0)),
                MAX(COALESCE(l.likes_after, 0)),
                MAX(COALESCE(l.comment_after, 0)),
                MAX(COALESCE(l.share_save_after, 0)),
                MAX(COALESCE(l.views_after, 0)),
                MAX(COALESCE(l.total_cost, 0)),
                MAX(COALESCE(l.updated_at, l.created_at, CONCAT(l.date, ' 00:00:00')))
            FROM endorse_logs l
            WHERE l.id_campaign = '$campaign'
              AND l.log_date BETWEEN $from AND $until
            GROUP BY l.id_endorse, l.log_date
        ");

        if ($result === false) {
            throw new RuntimeException('Gagal membangun ulang rollup endorse harian.');
        }
    }
}
