<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_endorse_refresh_attempts extends CI_Migration
{
    public function up()
    {
        $this->load->dbforge();

        if (!$this->db->table_exists('endorse_refresh_queue_attempts')) {
            $this->dbforge->add_field([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'queue_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'attempt_no' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 1,
                ],
                'worker_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 64,
                    'null' => true,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'default' => 'processing',
                ],
                'error_class' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => true,
                ],
                'error_message' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'started_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'finished_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('endorse_refresh_queue_attempts', true);
        }

        $this->db->query("CREATE INDEX idx_queue_attempt ON endorse_refresh_queue_attempts (queue_id, attempt_no)");
        $this->db->query("CREATE INDEX idx_worker_status ON endorse_refresh_queue_attempts (worker_id, status)");
    }

    public function down()
    {
        $this->load->dbforge();
        $this->dbforge->drop_table('endorse_refresh_queue_attempts', true);
    }
}
