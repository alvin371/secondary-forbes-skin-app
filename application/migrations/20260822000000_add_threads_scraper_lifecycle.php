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

        $this->ensureRetryingStatus();

        $columns = [
            'attempt_sequence' => 'INT NOT NULL DEFAULT 0',
            'active_attempt_id' => 'INT NULL',
            'claim_owner' => 'VARCHAR(32) NULL',
            // Existing production queues already use VARCHAR(64). Keep the
            // same bounded provider contract rather than requiring a table-copy
            // ALTER on a live queue.
            'provider_job_id' => 'VARCHAR(64) NULL',
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
                // This queue is large and receives live traffic. Do not permit
                // a table-copy fallback that could block workers for minutes.
                $this->queryOrFail("ALTER TABLE endorse_refresh_queue ADD COLUMN `$column` $definition, ALGORITHM=INSTANT");
            }
        }
        // Queue and attempt-table indexes are deliberately deferred. Production
        // already has a platform/status prefix index, no Threads rows exist yet,
        // and an online index build would still scan 894k-1m live rows. Add
        // capacity indexes only after a separately reviewed canary window.

        if (!$this->db->table_exists('threads_scraper_results')) {
            $this->queryOrFail('CREATE TABLE threads_scraper_results (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                queue_id INT UNSIGNED NOT NULL,
                provider_job_id VARCHAR(64) NOT NULL,
                result_hash CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_threads_result_queue (queue_id),
                UNIQUE KEY uq_threads_result_job (provider_job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        if (!$this->db->table_exists('threads_scraper_runs')) {
            $this->queryOrFail('CREATE TABLE threads_scraper_runs (
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

    private function ensureRetryingStatus(): void
    {
        $type = strtolower($this->columnType('endorse_refresh_queue', 'status'));
        if (strpos($type, "'retrying'") === false) {
            // Appending an ENUM value preserves the numeric values of all
            // existing statuses. MySQL must reject this rather than copy/lock.
            $this->queryOrFail("ALTER TABLE endorse_refresh_queue MODIFY COLUMN status ENUM('pending','processing','submitted','completed','failed','retrying') NOT NULL DEFAULT 'pending', ALGORITHM=INSTANT");
        }
    }

    private function columnType(string $table, string $column): string
    {
        $result = $this->db->query(
            'SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        $row = $result ? $result->row_array() : [];

        return strval($row['column_type'] ?? '');
    }

    private function queryOrFail(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Threads lifecycle migration refused an online schema operation.');
        }
    }
}
