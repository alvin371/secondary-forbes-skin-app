<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
defined('BASEPATH') || define('BASEPATH', __DIR__);
defined('APPPATH') || define('APPPATH', __DIR__ . '/../application/');
require_once APPPATH . 'libraries/Endorse_analytics_read_model.php';

/** item_series(): the per-content series both build() and the campaign snapshot use. */
final class EndorseAnalyticsItemSeriesTest extends TestCase
{
    private function series(array $rows, array $dates, string $entered): array
    {
        $rm = (new ReflectionClass(Endorse_analytics_read_model::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($rm, 'item_series');
        $m->setAccessible(true);
        return $m->invoke($rm, ['entered' => $entered], $rows, $dates);
    }

    public function test_first_sync_inside_range_is_opening_not_a_carried_future_value(): void
    {
        // Posted 09-29, first synced 10-01 (88), then 10-02 (92). Range starts before the first sync.
        $rows = [
            ['log_date' => '2026-10-01', 'views_after' => '88', 'prev_after' => null],
            ['log_date' => '2026-10-02', 'views_after' => '92', 'prev_after' => '88'],
        ];
        $s = $this->series($rows, ['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'], '2026-09-29');

        self::assertSame('belum_pernah_berhasil', $s['2026-09-29']['state']);
        self::assertSame(0, $s['2026-09-30']['total'], 'no value before the post was ever synced');
        self::assertSame('data_awal', $s['2026-10-01']['state']);
        self::assertSame(4, $s['2026-10-02']['kenaikan']);
    }

    public function test_predecessor_before_range_still_used(): void
    {
        $rows = [
            ['log_date' => '2026-09-01', 'views_after' => '50', 'prev_after' => null],
            ['log_date' => '2026-10-01', 'views_after' => '80', 'prev_after' => '50'],
        ];
        $s = $this->series($rows, ['2026-10-01'], '2026-09-01');
        self::assertSame('berhasil', $s['2026-10-01']['state']);
        self::assertSame(30, $s['2026-10-01']['kenaikan']);
    }
}
