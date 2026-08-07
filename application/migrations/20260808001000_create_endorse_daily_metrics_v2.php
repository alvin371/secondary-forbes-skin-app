<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Additive derived table for the V2 analytics read model.
 *
 * Materialises one row per (id_endorse, log_date) carrying the observation and
 * its authoritative predecessor, so the dashboard no longer pays for a LAG()
 * window over the join at request time. Campaign-scoped reads measured 3-19s
 * computed on read; this table replaces that with an indexed range scan.
 *
 * This is a CACHE, not a source of truth:
 *   - endorse_logs is never modified; it remains the evidence.
 *   - every row records calculation_version and source_checksum, so stale or
 *     drifted rows are detectable rather than silently trusted.
 *   - rebuilds are idempotent and can be scoped by campaign and date range.
 *   - reads are feature-flagged (ENDORSE_ANALYTICS_V2_DERIVED), so the derived
 *     path can be abandoned instantly in favour of compute-on-read.
 */
class Migration_Create_endorse_daily_metrics_v2 extends CI_Migration
{
    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_daily_metrics_v2 (
                id_endorse INT(11) UNSIGNED NOT NULL,
                log_date DATE NOT NULL,
                id_campaign INT(11) UNSIGNED NULL,
                content_id VARCHAR(64) NULL,
                views BIGINT NOT NULL DEFAULT 0,
                views_before BIGINT NOT NULL DEFAULT 0,
                views_after BIGINT NOT NULL DEFAULT 0,
                prev_after BIGINT NULL,
                prev_log_date DATE NULL,
                calculation_version VARCHAR(16) NOT NULL,
                source_checksum CHAR(32) NOT NULL,
                built_at DATETIME NOT NULL,
                PRIMARY KEY (id_endorse, log_date),
                INDEX idx_edm2_date_endorse (log_date, id_endorse),
                INDEX idx_edm2_campaign_date (id_campaign, log_date),
                INDEX idx_edm2_content (content_id, log_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS endorse_daily_metrics_v2');
    }
}
