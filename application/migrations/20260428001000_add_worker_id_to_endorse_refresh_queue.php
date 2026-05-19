<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_worker_id_to_endorse_refresh_queue extends CI_Migration
{
    public function up()
    {
        $this->load->dbforge();

        if ($this->db->table_exists('endorse_refresh_queue') && !$this->db->field_exists('worker_id', 'endorse_refresh_queue')) {
            $this->dbforge->add_column('endorse_refresh_queue', [
                'worker_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 64,
                    'null' => true,
                    'after' => 'claimed_at',
                ],
            ]);
        }

        $this->db->query("CREATE INDEX idx_worker ON endorse_refresh_queue (status, worker_id)");
    }

    public function down()
    {
        $this->load->dbforge();
        if ($this->db->table_exists('endorse_refresh_queue') && $this->db->field_exists('worker_id', 'endorse_refresh_queue')) {
            $this->dbforge->drop_column('endorse_refresh_queue', 'worker_id');
        }
    }
}
