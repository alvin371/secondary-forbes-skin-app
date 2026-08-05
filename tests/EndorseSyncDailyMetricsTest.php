<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_sync.php';

final class EndorseSyncDailyMetricsTest extends TestCase
{
    public function test_first_snapshot_counts_the_full_value_even_when_views_are_prepopulated(): void
    {
        $result = Endorse_sync::calculate_daily_metrics(
            [],
            [],
            ['likes' => 12, 'comment' => 4, 'share_save' => 3, 'views' => 68397],
            59812
        );

        self::assertSame(0.0, $result['before']['views']);
        self::assertSame(68397.0, $result['after']['views']);
        self::assertSame(68397.0, $result['delta']['views']);
    }

    public function test_second_sync_on_same_day_keeps_the_original_baseline(): void
    {
        $result = Endorse_sync::calculate_daily_metrics(
            ['views_after' => 32537],
            ['views_before' => 32537, 'likes_before' => 10, 'comment_before' => 2, 'share_save_before' => 1],
            ['likes' => 20, 'comment' => 5, 'share_save' => 4, 'views' => 50345],
            46418
        );

        self::assertSame(32537.0, $result['before']['views']);
        self::assertSame(50345.0, $result['after']['views']);
        self::assertSame(17808.0, $result['delta']['views']);
    }

    public function test_lower_upstream_view_cannot_decrease_the_cumulative_value(): void
    {
        $result = Endorse_sync::calculate_daily_metrics(
            ['views_after' => 1000],
            [],
            ['likes' => 0, 'comment' => 0, 'share_save' => 0, 'views' => 900],
            1200
        );

        self::assertSame(1000.0, $result['before']['views']);
        self::assertSame(1200.0, $result['after']['views']);
        self::assertSame(200.0, $result['delta']['views']);
    }
}
