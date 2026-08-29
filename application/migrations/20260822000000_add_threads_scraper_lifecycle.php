<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Additive schema for the asynchronous Threads-only queue state machine. */
class Migration_Add_threads_scraper_lifecycle extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('endorse_refresh_queue')) {
            return;
        }

        $columns = [
            'attempt_sequence' => 'INT NOT NULL DEFAULT 0',
            'active_attempt_id' => 'INT NULL',
            'claim_owner' => 'VARCHAR(32) NULL',
            'provider_job_id' => 'VARCHAR(191) NULL',
            'provider_request_key' => 'VARCHAR(191) NULL',
            'provider_submitted_at' => 'DATETIME NULL',
            'submitted_at' => 'DATETIME NULL',
            'next_poll_at' => 'DATETIME NULL',
            'next_retry_at' => 'DATETIME NULL',
            'provider_processing_deadline_at' => 'DATETIME NULL',
            'lease_expires_at' => 'DATETIME NULL',
            'submit_attempts' => 'INT NOT NULL DEFAULT 0',
            'poll_attempts' => 'INT NOT NULL DEFAULT 0',
            'transient_failures' => 'INT NOT NULL DEFAULT 0',
            'error_code' => 'VARCHAR(64) NULL',
            'user_error_message' => 'VARCHAR(512) NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->db->field_exists($column, 'endorse_refresh_queue')) {
                $this->db->query("ALTER TABLE endorse_refresh_queue ADD COLUMN `$column` $definition");
            }
        }
        $this->db->query('UPDATE endorse_refresh_queue SET submit_attempts = attempts WHERE submit_attempts = 0 AND attempts > 0');

        if ($this->db->table_exists('endorse_refresh_queue_attempts') && !$this->db->field_exists('error_code', 'endorse_refresh_queue_attempts')) {
            $this->db->query('ALTER TABLE endorse_refresh_queue_attempts ADD COLUMN error_code VARCHAR(64) NULL');
        }

        $this->createIndex('endorse_refresh_queue', 'idx_threads_submit', 'platform, status, worker_id, next_retry_at, priority, created_at');
        $this->createIndex('endorse_refresh_queue', 'idx_threads_poll', 'platform, status, worker_id, next_poll_at, provider_processing_deadline_at');
        $this->createIndex('endorse_refresh_queue', 'idx_threads_lease', 'platform, status, lease_expires_at');
        // Existing deployments may contain historical duplicate attempt numbers.
        // New Threads idempotency is enforced by threads_scraper_results; do not make
        // this legacy table migration fail before a separately reviewed cleanup.
        $this->createIndex('endorse_refresh_queue_attempts', 'idx_threads_attempt_lookup', 'queue_id, attempt_no, worker_id');

        if (!$this->db->table_exists('threads_scraper_results')) {
            $this->db->query('CREATE TABLE threads_scraper_results (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                queue_id INT UNSIGNED NOT NULL,
                provider_job_id VARCHAR(191) NOT NULL,
                result_hash CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_threads_result_queue (queue_id),
                UNIQUE KEY uq_threads_result_job (provider_job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        if (!$this->db->table_exists('threads_scraper_runs')) {
            $this->db->query('CREATE TABLE threads_scraper_runs (
                run_id VARCHAR(48) NOT NULL,
                status VARCHAR(16) NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                counters_json TEXT NULL,
                snapshot_json TEXT NULL,
                fatal_error_code VARCHAR(64) NULL,
                fatal_error_message VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (run_id),
                KEY idx_threads_runs_status_time (status, finished_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
    }

    public function down()
    {
        // Queue history is operational evidence. Rollback disables the code path;
        // it intentionally does not drop state tables or columns automatically.
    }

    private function createIndex(string $table, string $name, string $columns, bool $unique = false): void
    {
        $row = $this->db->query('SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$table, $name])->row_array();
        if (intval($row['c'] ?? 0) === 0) {
            $this->db->query('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX `$name` ON `$table` ($columns)");
        }
    }
}
