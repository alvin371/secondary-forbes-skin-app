<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Instagram exposes no public view count for photos and returns -1 likes when the
 * owner hides them. These columns record which metrics could not be fetched so
 * the UI can say why a number is 0 instead of implying it is real.
 */
class Migration_Add_instagram_metric_flags extends CI_Migration
{
    private $columns = [
        'endorse' => [
            // Comma list: photo_no_view, like_hidden
            'metric_flags' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        ],
        'influencer' => [
            // Out of the posts sampled for *_2 metrics (last 10)
            'ig_photo_posts' => ['type' => 'INT', 'constraint' => 11, 'null' => false, 'default' => 0],
            'ig_hidden_like_posts' => ['type' => 'INT', 'constraint' => 11, 'null' => false, 'default' => 0],
        ],
    ];

    public function up()
    {
        $this->load->dbforge();
        foreach ($this->columns as $table => $fields) {
            if (!$this->db->table_exists($table)) continue;
            foreach ($fields as $name => $definition) {
                if (!$this->db->field_exists($name, $table)) {
                    $this->dbforge->add_column($table, [$name => $definition]);
                }
            }
        }
    }

    public function down()
    {
        $this->load->dbforge();
        foreach ($this->columns as $table => $fields) {
            if (!$this->db->table_exists($table)) continue;
            foreach (array_keys($fields) as $name) {
                if ($this->db->field_exists($name, $table)) {
                    $this->dbforge->drop_column($table, $name);
                }
            }
        }
    }
}
