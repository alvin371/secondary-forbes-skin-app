<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Append-only provider observation log.
 *
 * The historical daily-delta defect exists because daily rows were mutable and
 * provider responses were never stored append-only, so exact midnight values
 * cannot be reconstructed. This table removes that limitation going forward:
 * every trustworthy provider success is written once and never updated.
 *
 * Additive only. Nothing reads this table yet, and writes are gated behind
 * ENDORSE_SNAPSHOTS_WRITE (default 0), so creating it changes no behaviour.
 *
 * Future daily reporting selects the last snapshot before the WIB day boundary
 * while retaining every intra-day observation, allowing exact as-of queries
 * within the available snapshot resolution.
 */
class Migration_Create_endorse_stats_snapshots extends CI_Migration
{
    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_stats_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_endorse INT(11) UNSIGNED NOT NULL,
                content_id VARCHAR(64) NULL,
                observed_at DATETIME NOT NULL,
                views BIGINT NOT NULL DEFAULT 0,
                likes BIGINT NOT NULL DEFAULT 0,
                comment BIGINT NOT NULL DEFAULT 0,
                share_save BIGINT NOT NULL DEFAULT 0,
                provider VARCHAR(64) NULL,
                queue_id BIGINT UNSIGNED NULL,
                attempt_id BIGINT UNSIGNED NULL,
                result_class VARCHAR(32) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_snap_endorse_observed (id_endorse, observed_at),
                INDEX idx_snap_observed (observed_at),
                INDEX idx_snap_content (content_id, observed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS endorse_stats_snapshots');
    }
}
