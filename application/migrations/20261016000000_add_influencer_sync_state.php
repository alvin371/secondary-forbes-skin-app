<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Per-influencer state of the automatic external sync (Template::influencer_sync_state()).
 * Before this the retry gate reused updated_at, so any profile edit postponed the daily
 * sync and a dead Instagram account was fetched forever.
 *
 * - sync_status: ok | retrying | not_found | private | invalid_url | failed (NULL = never tried).
 *   not_found/private/invalid_url/failed are parked: never fetched again until URL/type changes.
 * - sync_next_at: earliest next attempt after a failure (backoff).
 * sync_at keeps its meaning: time of the last successful external fetch.
 */
class Migration_Add_influencer_sync_state extends CI_Migration
{
    private $fields = [
        'sync_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        'sync_error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        'sync_fail_count' => ['type' => 'INT', 'constraint' => 11, 'null' => false, 'default' => 0],
        'sync_attempt_at' => ['type' => 'DATETIME', 'null' => true],
        'sync_next_at' => ['type' => 'DATETIME', 'null' => true],
    ];

    public function up()
    {
        $this->load->dbforge();
        if (!$this->db->table_exists('influencer')) return;
        foreach ($this->fields as $name => $definition) {
            if (!$this->db->field_exists($name, 'influencer')) {
                $this->dbforge->add_column('influencer', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        $this->load->dbforge();
        if (!$this->db->table_exists('influencer')) return;
        foreach (array_keys($this->fields) as $name) {
            if ($this->db->field_exists($name, 'influencer')) {
                $this->dbforge->drop_column('influencer', $name);
            }
        }
    }
}
