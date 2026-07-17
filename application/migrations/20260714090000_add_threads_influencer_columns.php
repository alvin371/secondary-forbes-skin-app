<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_threads_influencer_columns extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('influencer')) return;
        $fields = $this->db->list_fields('influencer');
        if (!in_array('threads_user_id', $fields, true)) $this->db->query("ALTER TABLE influencer ADD threads_user_id VARCHAR(50) DEFAULT ''");
        if (!in_array('threads_access_token', $fields, true)) $this->db->query('ALTER TABLE influencer ADD threads_access_token TEXT NULL');
        if (!in_array('threads_token_expires_at', $fields, true)) $this->db->query("ALTER TABLE influencer ADD threads_token_expires_at VARCHAR(25) DEFAULT ''");
    }

    public function down()
    {
        if (!$this->db->table_exists('influencer')) return;
        $fields = $this->db->list_fields('influencer');
        if (in_array('threads_token_expires_at', $fields, true)) $this->db->query('ALTER TABLE influencer DROP threads_token_expires_at');
        if (in_array('threads_access_token', $fields, true)) $this->db->query('ALTER TABLE influencer DROP threads_access_token');
        if (in_array('threads_user_id', $fields, true)) $this->db->query('ALTER TABLE influencer DROP threads_user_id');
    }
}
