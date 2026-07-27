<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_purpose_to_endorse_refresh_queue extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('endorse_refresh_queue')) {
            return;
        }

        if (!$this->db->field_exists('purpose', 'endorse_refresh_queue')) {
            $this->db->query("
                ALTER TABLE endorse_refresh_queue
                ADD COLUMN purpose VARCHAR(10) NOT NULL DEFAULT 'daily' AFTER link_upload
            ");
        }

        if (!$this->index_exists('endorse_refresh_queue', 'idx_purpose_dedup')) {
            $this->db->query("
                CREATE INDEX idx_purpose_dedup
                ON endorse_refresh_queue (id_endorse, purpose, status)
            ");
        }
    }

    public function down()
    {
        if (!$this->db->table_exists('endorse_refresh_queue')) {
            return;
        }
        if ($this->index_exists('endorse_refresh_queue', 'idx_purpose_dedup')) {
            $this->db->query('DROP INDEX idx_purpose_dedup ON endorse_refresh_queue');
        }
        if ($this->db->field_exists('purpose', 'endorse_refresh_queue')) {
            $this->db->query('ALTER TABLE endorse_refresh_queue DROP COLUMN purpose');
        }
    }

    private function index_exists($table, $index)
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?",
            [$table, $index]
        )->row();

        return $row && intval($row->cnt) > 0;
    }
}
