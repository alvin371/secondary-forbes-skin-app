<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_user_module_permission_indexes extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('user_module_permissions')) {
            return;
        }

        $this->create_index_if_missing(
            'user_module_permissions',
            'idx_ump_user_module',
            'CREATE INDEX idx_ump_user_module ON user_module_permissions (user_id, module_name)'
        );
        $this->create_index_if_missing(
            'user_module_permissions',
            'idx_ump_user_controller',
            'CREATE INDEX idx_ump_user_controller ON user_module_permissions (user_id, controller)'
        );
        $this->create_index_if_missing(
            'user_module_permissions',
            'idx_ump_user_module_id',
            'CREATE INDEX idx_ump_user_module_id ON user_module_permissions (user_id, module_id)'
        );
    }

    public function down()
    {
        $this->drop_index_if_exists('user_module_permissions', 'idx_ump_user_module');
        $this->drop_index_if_exists('user_module_permissions', 'idx_ump_user_controller');
        $this->drop_index_if_exists('user_module_permissions', 'idx_ump_user_module_id');
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
