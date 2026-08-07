<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_repair_plan.php';

final class EndorseRepairPlanTest extends TestCase
{
    /** Baseline row: campaign ownership consistent, not duplicate, not unresolved. */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 1, 'id_endorse' => 11677, 'id_campaign' => 27, 'endorse_campaign' => 27,
            'log_date' => '2026-08-03',
            'views_before' => 46418, 'views' => 3927, 'views_after' => 50345,
            'prev_after' => 32537,
            'is_duplicate' => false, 'is_unresolved' => false,
        ], $overrides);
    }

    // ---------------- historical calculation ----------------

    /** The canonical incident example: 32,537 -> 50,345 must yield 17,808. */
    public function test_broken_historical_row_is_repaired_to_the_convention(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row());

        self::assertSame(Endorse_repair_plan::CAT_SAFE, $p['category']);
        self::assertSame(32537, $p['proposed_views_before']);
        self::assertSame(17808, $p['proposed_views']);
        self::assertSame(13881, $p['delta_change']);
    }

    public function test_already_correct_row_is_not_rewritten(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row([
            'views_before' => 32537, 'views' => 17808,
        ]));
        self::assertSame(Endorse_repair_plan::CAT_ALREADY_CORRECT, $p['category']);
        self::assertSame(0, $p['delta_change']);
    }

    public function test_predecessor_from_before_the_window_is_used_not_zero(): void
    {
        // First in-window date, but a pre-window close exists.
        $p = Endorse_repair_plan::classify_row($this->row([
            'log_date' => '2026-07-27', 'prev_after' => 10000,
            'views_before' => 12000, 'views' => 500, 'views_after' => 12500,
        ]));
        self::assertSame(Endorse_repair_plan::CAT_SAFE, $p['category']);
        self::assertSame(10000, $p['proposed_views_before'], 'must not default to zero');
        self::assertSame(2500, $p['proposed_views']);
    }

    public function test_missing_predecessor_requires_an_opening_view_decision(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row(['prev_after' => null]));
        self::assertSame(Endorse_repair_plan::CAT_OPENING_DECISION, $p['category']);
    }

    public function test_negative_cumulative_change_is_never_auto_repaired(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row([
            'prev_after' => 60000, 'views_after' => 50345,
        ]));
        self::assertSame(Endorse_repair_plan::CAT_NEGATIVE, $p['category']);
    }

    /** Three refreshes in one day collapse to a single row; only the close matters. */
    public function test_multiple_same_day_refreshes_yield_one_full_day_delta(): void
    {
        foreach ([[40000, 1000], [46418, 3927], [49000, 1345]] as [$before, $views]) {
            $p = Endorse_repair_plan::classify_row($this->row([
                'views_before' => $before, 'views' => $views,
            ]));
            self::assertSame(17808, $p['proposed_views'],
                'whatever intra-day value was stored, the repaired delta is close - predecessor');
        }
    }

    // ---------------- safety invariants ----------------

    public function test_row_dated_2026_08_06_or_later_can_never_be_repaired(): void
    {
        foreach (['2026-08-06', '2026-08-07', '2026-09-01'] as $d) {
            $p = Endorse_repair_plan::classify_row($this->row(['log_date' => $d]));
            self::assertNotSame(Endorse_repair_plan::CAT_SAFE, $p['category'], $d);
        }
    }

    public function test_row_before_the_window_can_never_be_repaired(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row(['log_date' => '2026-07-26']));
        self::assertNotSame(Endorse_repair_plan::CAT_SAFE, $p['category']);
    }

    public function test_ownership_drift_is_excluded(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row(['endorse_campaign' => 39]));
        self::assertSame(Endorse_repair_plan::CAT_OWNERSHIP, $p['category']);
    }

    public function test_duplicate_sensitive_rows_are_excluded(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row(['is_duplicate' => true]));
        self::assertSame(Endorse_repair_plan::CAT_DUPLICATE_SENSITIVE, $p['category']);
    }

    public function test_provider_unresolved_rows_are_excluded(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row(['is_unresolved' => true]));
        self::assertSame(Endorse_repair_plan::CAT_UNRESOLVED, $p['category']);
    }

    public function test_malformed_date_is_excluded(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row(['log_date' => 'not-a-date']));
        self::assertNotSame(Endorse_repair_plan::CAT_SAFE, $p['category']);
    }

    /** views_after is never part of the proposal. */
    public function test_plan_never_proposes_a_new_views_after(): void
    {
        $p = Endorse_repair_plan::classify_row($this->row());
        self::assertArrayNotHasKey('proposed_views_after', $p);
    }

    // ---------------- idempotency / checksum ----------------

    public function test_repairing_an_already_repaired_row_is_a_no_op(): void
    {
        $first = Endorse_repair_plan::classify_row($this->row());
        $second = Endorse_repair_plan::classify_row($this->row([
            'views_before' => $first['proposed_views_before'],
            'views' => $first['proposed_views'],
        ]));
        self::assertSame(Endorse_repair_plan::CAT_ALREADY_CORRECT, $second['category']);
    }

    public function test_checksum_is_order_independent_and_value_sensitive(): void
    {
        $a = ['id' => 1, 'views_before' => 1, 'views' => 2, 'views_after' => 3];
        $b = ['id' => 2, 'views_before' => 4, 'views' => 5, 'views_after' => 9];

        self::assertSame(
            Endorse_repair_plan::checksum([$a, $b]),
            Endorse_repair_plan::checksum([$b, $a]),
            'ordering must not change the checksum'
        );
        self::assertNotSame(
            Endorse_repair_plan::checksum([$a, $b]),
            Endorse_repair_plan::checksum([$a, ['id' => 2, 'views_before' => 4, 'views' => 6, 'views_after' => 9]]),
            'a changed value must change the checksum'
        );
    }

    public function test_window_bounds_are_exactly_the_incident_range(): void
    {
        self::assertSame('2026-07-27', Endorse_repair_plan::WINDOW_MIN);
        self::assertSame('2026-08-05', Endorse_repair_plan::WINDOW_MAX);
        self::assertTrue(Endorse_repair_plan::is_within_window('2026-07-27'));
        self::assertTrue(Endorse_repair_plan::is_within_window('2026-08-05'));
        self::assertFalse(Endorse_repair_plan::is_within_window('2026-08-06'));
    }
}
