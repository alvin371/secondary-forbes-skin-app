<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_influencer_username_index extends CI_Migration
{
    public function up()
    {
        if ($this->db->table_exists('influencer') && !$this->index_exists('influencer', 'idx_influencer_username')) {
            $this->db->query("CREATE INDEX idx_influencer_username ON influencer (username)");
        }
    }

    public function down()
    {
        if ($this->db->table_exists('influencer') && $this->index_exists('influencer', 'idx_influencer_username')) {
            $this->db->query("DROP INDEX idx_influencer_username ON influencer");
        }
    }

    private function index_exists($table, $index)
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            array($table, $index)
        )->row();

        return $row && (int) $row->cnt > 0;
    }
}
