<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QueueSchemaCompatibilityTest extends TestCase
{
    public function test_queue_endpoint_falls_back_when_threads_lifecycle_columns_are_absent(): void
    {
        $controller = file_get_contents(__DIR__ . '/../application/controllers/Endorse.php');
        $service = file_get_contents(__DIR__ . '/../application/libraries/EndorseRefreshQueueService.php');

        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertStringContainsString('queueFieldOrNull', $controller);
        self::assertStringContainsString("'submitted_at'", $controller);
        self::assertStringContainsString("'next_poll_at'", $controller);
        self::assertStringContainsString("'next_retry_at'", $controller);
        self::assertStringContainsString("'error_code'", $controller);
        self::assertStringContainsString("'user_error_message'", $controller);
        self::assertStringContainsString("field_exists('submitted_at', 'endorse_refresh_queue')", $service);
    }
}
