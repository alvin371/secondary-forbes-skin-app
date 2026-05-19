<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_tiktok_media_columns_to_endorse extends CI_Migration
{
    public function up()
    {
        $this->load->dbforge();

        if ($this->db->table_exists('endorse')) {
            $columns = [
                'tiktok_content_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                    'null' => true,
                ],
                'tiktok_media_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'null' => true,
                ],
                'tiktok_cover' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'tiktok_content_link' => [
                    'type' => 'LONGTEXT',
                    'null' => true,
                ],
                'tiktok_fetched_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ];

            foreach ($columns as $name => $definition) {
                if (!$this->db->field_exists($name, 'endorse')) {
                    $this->dbforge->add_column('endorse', [$name => $definition]);
                }
            }

            $this->db->query("CREATE INDEX idx_campaign_status ON endorse (id_campaign, status, status_campaign)");
        }

        if ($this->db->table_exists('endorse_logs')) {
            $this->db->query("CREATE INDEX idx_id_endorse_date ON endorse_logs (id_endorse, date)");
        }
    }

    public function down()
    {
        $this->load->dbforge();

        $fields = [
            'tiktok_content_id',
            'tiktok_media_type',
            'tiktok_cover',
            'tiktok_content_link',
            'tiktok_fetched_at',
        ];

        foreach ($fields as $field) {
            if ($this->db->table_exists('endorse') && $this->db->field_exists($field, 'endorse')) {
                $this->dbforge->drop_column('endorse', $field);
            }
        }
    }
}
