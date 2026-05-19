<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migrate extends CI_Controller
{
    protected $migrationTable = 'migrations';

    public function __construct()
    {
        parent::__construct();

        if (!$this->input->is_cli_request()) {
            show_error('Migration runner is CLI-only.', 403);
        }

        $this->load->database();
        $config = [
            'migration_enabled' => true,
            'migration_type' => 'timestamp',
            'migration_path' => APPPATH . 'migrations/',
            'migration_table' => $this->migrationTable,
            'migration_auto_latest' => false,
            'migration_version' => 0,
        ];
        $this->load->library('migration', $config);
    }

    public function index()
    {
        return $this->run();
    }

    public function run()
    {
        $this->line('Running latest migration...');
        if ($this->migration->latest() === false) {
            return $this->fail($this->migration->error_string());
        }

        return $this->status(true, 'Latest migration applied successfully.');
    }

    public function version($target = null)
    {
        $target = $target !== null ? intval($target) : 0;
        if ($target <= 0) {
            return $this->fail('Target migration version is required.');
        }

        $this->line('Migrating to version: ' . $target);
        if ($this->migration->version($target) === false) {
            return $this->fail($this->migration->error_string());
        }

        return $this->status(true, 'Migration version applied successfully.', $target);
    }

    public function status($success = null, $message = null, $targetVersion = null)
    {
        $row = $this->db->get($this->migrationTable)->row_array();
        $currentVersion = intval($row['version'] ?? 0);

        if ($success === null) {
            $this->line('Current migration version: ' . $currentVersion);
            return;
        }

        $this->line($message ?: 'Migration command finished.');
        $this->line('Current migration version: ' . $currentVersion);
        if ($targetVersion !== null) {
            $this->line('Target migration version: ' . intval($targetVersion));
        }
        exit($success ? 0 : 1);
    }

    protected function fail($message)
    {
        return $this->status(false, 'Migration failed: ' . trim((string) $message));
    }

    protected function line($message)
    {
        echo rtrim((string) $message) . PHP_EOL;
    }
}
