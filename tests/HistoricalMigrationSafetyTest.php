<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HistoricalMigrationSafetyTest extends TestCase
{
    public function test_historical_rebuild_migration_is_inert(): void
    {
        $source = file_get_contents(__DIR__ . '/../application/migrations/20260805090000_rebuild_nipple_serum_daily_deltas.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('rebuild_daily_deltas(', $source);
        self::assertStringNotContainsString('DELETE r', $source);
        self::assertStringNotContainsString('INSERT INTO endorse_logs_daily_rollup', $source);
    }

    public function test_threads_queue_schema_migration_is_online_and_write_free(): void
    {
        $source = file_get_contents(__DIR__ . '/../application/migrations/20260822000000_add_threads_scraper_lifecycle.php');

        self::assertIsString($source);
        self::assertStringContainsString('ALGORITHM=INSTANT', $source);
        self::assertStringContainsString("'retrying'", $source);
        self::assertStringContainsString('VARCHAR(64)', $source);
        self::assertStringNotContainsString('UPDATE endorse_refresh_queue', $source);
        self::assertStringNotContainsString('CREATE INDEX', $source);
        self::assertStringNotContainsString('endorse_refresh_queue_attempts ADD COLUMN', $source);
        self::assertStringNotContainsString('MODIFY COLUMN provider_job_id', $source);
    }
}
