<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Create_endorse_refresh_queue extends CI_Migration
{
    public function up()
    {
        $this->load->dbforge();

        if (!$this->db->table_exists('endorse_refresh_queue')) {
            $this->dbforge->add_field([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'id_endorse' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'id_campaign' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'platform' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                    'null' => true,
                ],
                'link_upload' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'default' => 'pending',
                ],
                'priority' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 10,
                ],
                'attempts' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 0,
                ],
                'max_attempts' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 3,
                ],
                'error_message' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'claimed_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'enqueued_by' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'null' => true,
                ],
                'retry_source_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'null' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'started_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'completed_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->dbforge->add_key('id', true);
            $this->dbforge->create_table('endorse_refresh_queue', true);
        }

        $this->db->query("CREATE INDEX idx_pop ON endorse_refresh_queue (status, priority, created_at)");
        $this->db->query("CREATE INDEX idx_dedup ON endorse_refresh_queue (id_endorse, status)");
        $this->db->query("CREATE INDEX idx_campaign_status ON endorse_refresh_queue (id_campaign, status)");
        $this->db->query("CREATE INDEX idx_retry_source ON endorse_refresh_queue (retry_source_id)");
    }

    public function down()
    {
        $this->load->dbforge();
        $this->dbforge->drop_table('endorse_refresh_queue', true);
    }
}
