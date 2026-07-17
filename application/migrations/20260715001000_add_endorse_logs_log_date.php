<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_endorse_logs_log_date extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('endorse_logs')) {
            return;
        }

        if (!$this->db->field_exists('log_date', 'endorse_logs')) {
            $this->db->query("
                ALTER TABLE endorse_logs
                ADD COLUMN log_date DATE GENERATED ALWAYS AS (
                    CASE
                        WHEN `date` IS NULL OR TRIM(`date`) = '' THEN NULL
                        WHEN CHAR_LENGTH(`date`) >= 10 THEN STR_TO_DATE(LEFT(`date`, 10), '%Y-%m-%d')
                        ELSE NULL
                    END
                ) STORED
            ");
        }

        $this->create_index_if_missing(
            'idx_endorse_logs_endorse_log_date',
            'CREATE INDEX idx_endorse_logs_endorse_log_date ON endorse_logs (id_endorse, log_date)'
        );
        $this->create_index_if_missing(
            'idx_endorse_logs_log_date_endorse',
            'CREATE INDEX idx_endorse_logs_log_date_endorse ON endorse_logs (log_date, id_endorse)'
        );
    }

    public function down()
    {
        if (!$this->db->table_exists('endorse_logs')) {
            return;
        }
        $this->drop_index_if_exists('idx_endorse_logs_endorse_log_date');
        $this->drop_index_if_exists('idx_endorse_logs_log_date_endorse');
        if ($this->db->field_exists('log_date', 'endorse_logs')) {
            $this->db->query('ALTER TABLE endorse_logs DROP COLUMN log_date');
        }
    }

    private function create_index_if_missing($index, $sql)
    {
        if (!$this->index_exists($index)) {
            $this->db->query($sql);
        }
    }

    private function drop_index_if_exists($index)
    {
        if ($this->index_exists($index)) {
            $this->db->query("DROP INDEX {$index} ON endorse_logs");
        }
    }

    private function index_exists($index)
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'endorse_logs'
               AND index_name = ?",
            [$index]
        )->row();

        return $row && intval($row->cnt) > 0;
    }
}
