<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Create_endorse_logs_daily_rollup extends CI_Migration
{
    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS endorse_logs_daily_rollup (
                id_endorse INT(11) UNSIGNED NOT NULL,
                log_date DATE NOT NULL,
                likes_delta BIGINT NOT NULL DEFAULT 0,
                comment_delta BIGINT NOT NULL DEFAULT 0,
                share_save_delta BIGINT NOT NULL DEFAULT 0,
                views_delta BIGINT NOT NULL DEFAULT 0,
                likes_after BIGINT NOT NULL DEFAULT 0,
                comment_after BIGINT NOT NULL DEFAULT 0,
                share_save_after BIGINT NOT NULL DEFAULT 0,
                views_after BIGINT NOT NULL DEFAULT 0,
                total_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
                last_updated DATETIME NULL,
                PRIMARY KEY (id_endorse, log_date),
                INDEX idx_endorse_logs_rollup_date (log_date, id_endorse)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS endorse_logs_daily_rollup');
    }
}
