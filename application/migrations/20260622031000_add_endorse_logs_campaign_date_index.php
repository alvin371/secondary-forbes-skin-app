<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_endorse_logs_campaign_date_index extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('endorse_logs')) {
            return;
        }

        $this->create_index_if_missing(
            'endorse_logs',
            'idx_campaign_date_endorse',
            'CREATE INDEX idx_campaign_date_endorse ON endorse_logs (id_campaign, date, id_endorse)'
        );
    }

    public function down()
    {
        $this->drop_index_if_exists('endorse_logs', 'idx_campaign_date_endorse');
    }

    private function create_index_if_missing($table, $index, $sql)
    {
        if (!$this->index_exists($table, $index)) {
            $this->db->query($sql);
        }
    }

    private function drop_index_if_exists($table, $index)
    {
        if ($this->db->table_exists($table) && $this->index_exists($table, $index)) {
            $this->db->query("DROP INDEX {$index} ON {$table}");
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

        return $row && (int) $row->cnt > 0;
    }
}
