<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Dedupe_endorse_logs_and_add_unique_index extends CI_Migration
{
    private $table = 'endorse_logs';
    private $legacyIndex = 'idx_id_endorse_date';
    private $uniqueIndex = 'uniq_id_endorse_date';

    public function up()
    {
        if (!$this->db->table_exists($this->table)) {
            return;
        }

        $this->emit('Deduplicating endorse_logs...');
        $deleted = $this->delete_duplicate_rows_in_batches();
        $this->emit('Deleted duplicate endorse_logs rows: ' . $deleted);

        if ($this->index_exists($this->table, $this->legacyIndex)) {
            $this->db->query("DROP INDEX {$this->legacyIndex} ON {$this->table}");
        }

        if (!$this->index_exists($this->table, $this->uniqueIndex)) {
            $this->db->query("
                ALTER TABLE {$this->table}
                ADD UNIQUE INDEX {$this->uniqueIndex} (id_endorse, date)
            ");
        }
    }

    public function down()
    {
        if (!$this->db->table_exists($this->table)) {
            return;
        }

        if ($this->index_exists($this->table, $this->uniqueIndex)) {
            $this->db->query("DROP INDEX {$this->uniqueIndex} ON {$this->table}");
        }

        if (!$this->index_exists($this->table, $this->legacyIndex)) {
            $this->db->query("
                CREATE INDEX {$this->legacyIndex}
                ON {$this->table} (id_endorse, date)
            ");
        }
    }

    private function delete_duplicate_rows_in_batches(): int
    {
        $deletedTotal = 0;

        do {
            $this->db->query("
                DELETE l1
                FROM {$this->table} l1
                INNER JOIN {$this->table} l2
                    ON l1.id_endorse = l2.id_endorse
                   AND l1.date = l2.date
                   AND l1.id < l2.id
                LIMIT 10000
            ");
            $deletedNow = intval($this->db->affected_rows());
            $deletedTotal += max(0, $deletedNow);
        } while ($deletedNow > 0);

        return $deletedTotal;
    }

    private function index_exists($table, $index): bool
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?",
            [$table, $index]
        )->row();

        return $row && intval($row->cnt) > 0;
    }

    private function emit(string $message): void
    {
        log_message('info', $message);
        if (PHP_SAPI === 'cli') {
            echo $message . PHP_EOL;
        }
    }
}
