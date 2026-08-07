<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Additive audit store for the historical endorse daily-delta repair.
 *
 * Nothing in endorse_logs is redefined. Every automatic write records its
 * before/after values here so a run can be reversed row-by-row with an
 * optimistic check.
 */
class Migration_Create_endorse_logs_repair_audit extends CI_Migration
{
    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_logs_repair_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id VARCHAR(64) NOT NULL,
                action VARCHAR(16) NOT NULL DEFAULT 'apply',
                operator VARCHAR(64) NULL,
                log_id INT NOT NULL,
                id_endorse INT NOT NULL,
                id_campaign INT NOT NULL,
                log_date DATE NOT NULL,
                old_views_before BIGINT NOT NULL,
                old_views BIGINT NOT NULL,
                new_views_before BIGINT NOT NULL,
                new_views BIGINT NOT NULL,
                views_after BIGINT NOT NULL,
                preview_checksum CHAR(32) NULL,
                apply_checksum CHAR(32) NULL,
                reason VARCHAR(64) NULL,
                source_sha VARCHAR(40) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_run_log (run_id, action, log_id),
                KEY idx_run (run_id),
                KEY idx_log (log_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS endorse_logs_repair_audit');
    }
}
