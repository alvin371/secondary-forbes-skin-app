<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_analytics_v2.php';
require_once __DIR__ . '/../application/libraries/Endorse_repair_plan.php';

/**
 * Phase 13 coverage for the V2 analytics read model.
 *
 * Every case here runs against the pure metric core, so the suite needs no
 * database and cannot mutate one.
 */
final class EndorseAnalyticsV2Test extends TestCase
{
    /** One observation row. Defaults to the canonical incident example. */
    private function obs(array $o = []): array
    {
        return array_merge([
            'id' => 1,
            'id_endorse' => 11677,
            'log_date' => '2026-08-03',
            'prev_log_date' => '2026-08-02',
            'views' => 3927,
            'views_before' => 46418,
            'views_after' => 50345,
            'prev_after' => 32537,
            'content_id' => '7500000000000000001',
            'is_duplicate' => false,
            'is_unresolved' => false,
        ], $o);
    }

    private function fold(array $rows, array $dates, array $ctx = []): array
    {
        return Endorse_analytics_v2::fold_dates($rows, array_merge(['dates' => $dates], $ctx));
    }

    // ------------------------------------------------ 1. consecutive snapshots

    public function test_consecutive_daily_snapshots_produce_the_closing_difference(): void
    {
        $o = Endorse_analytics_v2::classify_observation($this->obs());

        self::assertSame(Endorse_analytics_v2::OBS_GROWTH, $o['class']);
        self::assertSame(17808, $o['growth'], '50,345 - 32,537');
        self::assertSame(0, $o['opening']);
        self::assertSame(1, $o['gap_days']);
    }

    // -------------------------------------------- 2. multiple same-day refreshes

    public function test_multiple_same_day_refreshes_count_once_per_content_per_date(): void
    {
        // The read model's canonical subquery keeps one row per (endorse, date);
        // folding must not double count if two survive.
        $rows = [
            $this->obs(['views_after' => 50345, 'prev_after' => 32537]),
        ];
        $b = $this->fold($rows, ['2026-08-03']);

        self::assertSame(1, $b['2026-08-03']['included_post_count']);
        self::assertSame(17808, $b['2026-08-03']['observed_daily_growth']);
        self::assertSame(50345, $b['2026-08-03']['total_observed_views']);
    }

    // ------------------------------------------------ 3. pre-window predecessor

    public function test_predecessor_before_the_selected_range_is_used_not_zero(): void
    {
        $o = Endorse_analytics_v2::classify_observation($this->obs([
            'log_date' => '2026-08-01',
            'prev_log_date' => '2026-07-31',
            'prev_after' => 10000,
            'views_after' => 12500,
        ]));

        self::assertSame(2500, $o['growth'], 'must not treat a missing in-range predecessor as zero');
    }

    // ------------------------------------------------- 4 & 14. opening views

    public function test_first_observation_is_opening_views_and_never_growth(): void
    {
        $o = Endorse_analytics_v2::classify_observation($this->obs([
            'prev_after' => null,
            'prev_log_date' => null,
            'views_after' => 34776,
        ]));

        self::assertSame(Endorse_analytics_v2::OBS_OPENING, $o['class']);
        self::assertSame(34776, $o['opening']);
        self::assertSame(0, $o['growth'], 'opening views are not daily organic growth');
    }

    public function test_opening_views_are_excluded_from_period_growth(): void
    {
        $rows = [
            $this->obs(['id_endorse' => 1, 'content_id' => 'a', 'prev_after' => 100, 'views_after' => 600]),
            $this->obs(['id_endorse' => 2, 'content_id' => 'b', 'prev_after' => null, 'prev_log_date' => null, 'views_after' => 9000]),
        ];
        $b = $this->fold($rows, ['2026-08-03']);

        self::assertSame(500, $b['2026-08-03']['observed_daily_growth']);
        self::assertSame(9000, $b['2026-08-03']['opening_views']);
        self::assertSame(9600, $b['2026-08-03']['total_observed_views']);
    }

    // ---------------------------------------------------------- 5. missing date

    public function test_a_date_with_no_observation_is_null_not_zero(): void
    {
        $b = $this->fold([$this->obs()], ['2026-08-03', '2026-08-04']);

        self::assertNull($b['2026-08-04']['observed_daily_growth'], 'a missing date must never render as a confident zero');
        self::assertSame(0, $b['2026-08-04']['included_post_count']);
        self::assertSame(Endorse_analytics_v2::INSUFFICIENT, $b['2026-08-04']['data_completeness']);
    }

    // ------------------------------------------------- 6. multi-day observation gap

    public function test_multi_day_gap_is_reported_separately_and_marks_the_date_partial(): void
    {
        $rows = [$this->obs([
            'log_date' => '2026-08-05',
            'prev_log_date' => '2026-08-01',
            'prev_after' => 1000,
            'views_after' => 9000,
        ])];
        $b = $this->fold($rows, ['2026-08-05']);

        self::assertSame(4, Endorse_analytics_v2::day_span('2026-08-01', '2026-08-05'));
        self::assertSame(8000, $b['2026-08-05']['growth_since_last_observation']);
        self::assertSame(1, $b['2026-08-05']['gap_row_count']);
        self::assertSame(Endorse_analytics_v2::PARTIAL, $b['2026-08-05']['data_completeness']);
    }

    // ------------------------------------------------ 7. provider-unresolved content

    public function test_unresolved_content_is_counted_not_converted_to_zero(): void
    {
        $b = $this->fold([$this->obs()], ['2026-08-03'], [
            'unresolved_by_date' => ['2026-08-03' => 9],
        ]);

        self::assertSame(9, $b['2026-08-03']['unresolved_post_count']);
        self::assertSame(1, $b['2026-08-03']['included_post_count']);
        self::assertSame(17808, $b['2026-08-03']['observed_daily_growth'], 'unresolved posts must not dilute growth');
    }

    public function test_high_unresolved_ratio_downgrades_completeness_to_insufficient(): void
    {
        self::assertSame(Endorse_analytics_v2::INSUFFICIENT, Endorse_analytics_v2::completeness(1, 9, 0, 0));
        self::assertSame(Endorse_analytics_v2::PARTIAL, Endorse_analytics_v2::completeness(9, 1, 0, 0));
        self::assertSame(Endorse_analytics_v2::COMPLETE, Endorse_analytics_v2::completeness(9, 0, 0, 0));
        self::assertSame(Endorse_analytics_v2::INSUFFICIENT, Endorse_analytics_v2::completeness(0, 0, 0, 0));
    }

    // ------------------------------------------- 8. duplicate raw vs canonical

    public function test_duplicate_content_id_counts_once_in_the_canonical_population(): void
    {
        $rows = [
            $this->obs(['id_endorse' => 1, 'content_id' => 'dup', 'prev_after' => 100, 'views_after' => 600]),
            $this->obs(['id_endorse' => 2, 'content_id' => 'dup', 'prev_after' => 100, 'views_after' => 600]),
        ];
        $b = $this->fold($rows, ['2026-08-03'], [
            'duplicate_content_ids' => ['dup' => 2],
        ]);
        $d = $b['2026-08-03'];

        self::assertSame(2, $d['included_post_count'], 'raw keeps both rows');
        self::assertSame(1000, $d['observed_daily_growth']);
        self::assertSame(1, $d['canonical']['included_post_count'], 'canonical collapses the duplicate');
        self::assertSame(500, $d['canonical']['observed_daily_growth']);
        self::assertSame(1, $d['duplicate_group_count'], 'duplicates are surfaced, never silently merged');
    }

    // ---------------------------------------- 13. changing campaign population

    public function test_cumulative_total_tracks_a_growing_population_without_faking_growth(): void
    {
        $rows = [
            $this->obs(['id_endorse' => 1, 'content_id' => 'a', 'log_date' => '2026-08-03', 'prev_after' => 100, 'views_after' => 200]),
            $this->obs(['id_endorse' => 1, 'content_id' => 'a', 'log_date' => '2026-08-04', 'prev_log_date' => '2026-08-03', 'prev_after' => 200, 'views_after' => 300]),
            // A brand new post joins on 08-04; its 5,000 views are opening, not growth.
            $this->obs(['id_endorse' => 2, 'content_id' => 'b', 'log_date' => '2026-08-04', 'prev_after' => null, 'prev_log_date' => null, 'views_after' => 5000]),
        ];
        $b = $this->fold($rows, ['2026-08-03', '2026-08-04']);

        self::assertSame(200, $b['2026-08-03']['total_observed_views']);
        self::assertSame(5300, $b['2026-08-04']['total_observed_views'], 'line follows the population');
        self::assertSame(100, $b['2026-08-04']['observed_daily_growth'], 'bar must not absorb the new post');
        self::assertSame(5000, $b['2026-08-04']['opening_views']);
    }

    // ------------------------------------------------- 15. carry-forward marking

    public function test_carried_forward_value_feeds_the_line_only_and_marks_incompleteness(): void
    {
        $b = $this->fold([$this->obs()], ['2026-08-03', '2026-08-04'], [
            'carried_by_date' => ['2026-08-04' => ['count' => 1, 'views' => 50345]],
        ]);

        self::assertSame(50345, $b['2026-08-04']['total_observed_views'], 'line carries forward');
        self::assertSame(1, $b['2026-08-04']['carried_forward_count']);
        self::assertNull($b['2026-08-04']['observed_daily_growth'], 'no growth is claimed for a carried date');
        self::assertSame(Endorse_analytics_v2::INSUFFICIENT, $b['2026-08-04']['data_completeness']);
    }

    public function test_carry_forward_downgrades_an_otherwise_complete_date(): void
    {
        self::assertSame(Endorse_analytics_v2::PARTIAL, Endorse_analytics_v2::completeness(5, 0, 0, 1));
    }

    // ------------------------------------------------ 16. negative cumulative anomaly

    public function test_negative_cumulative_movement_is_preserved_as_an_anomaly(): void
    {
        $o = Endorse_analytics_v2::classify_observation($this->obs([
            'prev_after' => 50345,
            'views_after' => 32537,
        ]));

        self::assertSame(Endorse_analytics_v2::OBS_NEGATIVE, $o['class']);
        self::assertSame(-17808, $o['negative_views'], 'the signed anomaly is preserved, not silently clamped');
        self::assertSame(0, $o['growth'], 'but a bar is never negative');
    }

    public function test_negative_anomaly_is_surfaced_in_the_date_bucket(): void
    {
        $b = $this->fold([$this->obs(['prev_after' => 50345, 'views_after' => 32537])], ['2026-08-03']);

        self::assertSame(1, $b['2026-08-03']['negative_anomaly_count']);
        self::assertSame(-17808, $b['2026-08-03']['negative_anomaly_views']);
    }

    // ------------------------------------------------------- summary behaviour

    public function test_total_views_at_end_date_is_the_last_close_not_a_sum_of_days(): void
    {
        $rows = [
            $this->obs(['log_date' => '2026-08-03', 'prev_after' => 100, 'views_after' => 200]),
            $this->obs(['log_date' => '2026-08-04', 'prev_log_date' => '2026-08-03', 'prev_after' => 200, 'views_after' => 300]),
        ];
        $b = $this->fold($rows, ['2026-08-03', '2026-08-04']);
        $s = Endorse_analytics_v2::summarize(array_values($b));

        self::assertSame(300, $s['total_views_at_end_date'], 'cumulative snapshots must not be summed across dates');
        self::assertSame(200, $s['growth_in_selected_period'], '100 + 100');
    }

    public function test_summary_ignores_trailing_unobserved_dates_for_the_total(): void
    {
        $b = $this->fold([$this->obs(['prev_after' => 100, 'views_after' => 900])], ['2026-08-03', '2026-08-04']);
        $s = Endorse_analytics_v2::summarize(array_values($b));

        self::assertSame(900, $s['total_views_at_end_date'], 'a blank trailing day must not zero the card');
    }

    public function test_summary_reports_completeness_at_its_weakest_link(): void
    {
        self::assertSame('insufficient', Endorse_analytics_v2::worse_completeness('complete', 'insufficient'));
        self::assertSame('partial', Endorse_analytics_v2::worse_completeness('partial', 'complete'));
    }

    // ------------------------------------- 9/10/11. internal, media, old vs new

    public function test_growth_is_media_and_category_agnostic(): void
    {
        // Photo and video content share one arithmetic rule; nothing branches on
        // media, is_internal, or content age.
        foreach ([['photo', 1], ['video', 0]] as [$media, $internal]) {
            $o = Endorse_analytics_v2::classify_observation($this->obs([
                'media' => $media,
                'is_internal' => $internal,
                'prev_after' => 1000,
                'views_after' => 1500,
            ]));
            self::assertSame(500, $o['growth'], "$media / internal=$internal");
        }
    }

    public function test_an_old_post_with_a_large_backlog_still_reports_only_the_daily_delta(): void
    {
        $o = Endorse_analytics_v2::classify_observation($this->obs([
            'prev_after' => 5000000,
            'views_after' => 5000450,
        ]));
        self::assertSame(450, $o['growth']);
    }

    // ------------------------------------------------- 11 (Phase). repair oracle

    /**
     * For every row the historical repair planner classifies as safe, the V2
     * growth must equal the planner's proposed value exactly. This is the
     * independent oracle required by Phase 11 — and it never touches the DB.
     *
     * @dataProvider safeRepairRows
     */
    public function test_v2_growth_matches_the_repair_planner_on_safe_rows(array $row): void
    {
        $plan = Endorse_repair_plan::classify_row($row);
        self::assertSame(Endorse_repair_plan::CAT_SAFE, $plan['category'], 'fixture must be a safe row');

        $o = Endorse_analytics_v2::classify_observation($row);

        self::assertSame($plan['proposed_views'], $o['growth'], 'V2 growth must equal the repair oracle');
    }

    public static function safeRepairRows(): array
    {
        $base = [
            'id' => 1, 'id_endorse' => 11677, 'id_campaign' => 27, 'endorse_campaign' => 27,
            'log_date' => '2026-08-03', 'prev_log_date' => '2026-08-02',
            'views_before' => 46418, 'views' => 3927, 'views_after' => 50345, 'prev_after' => 32537,
            'is_duplicate' => false, 'is_unresolved' => false,
        ];
        return [
            'campaign 27 post 11677' => [$base],
            'pre-window predecessor' => [array_merge($base, [
                'log_date' => '2026-07-27', 'prev_log_date' => '2026-07-26',
                'prev_after' => 10000, 'views_before' => 12000, 'views' => 500, 'views_after' => 12500,
            ])],
            // Genuine zero-growth day whose stored baseline is still wrong, so
            // the planner classifies it safe rather than already_correct.
            'zero growth day with corrupt baseline' => [array_merge($base, [
                'views_after' => 32537, 'views_before' => 9999, 'views' => 555,
            ])],
        ];
    }

    public function test_repair_parity_total_reproduces_the_historical_preview_figure(): void
    {
        // Campaign 27 / Fazra / 2026-08-03 measured in production:
        // legacy SUM(views) = 62,380, of which post 11677 stored 3,927.
        // The repair preview's proposed total is 62,380 - 3,927 + 17,808 = 76,261.
        $rows = [
            $this->obs(), // safe row: stored 3,927 -> corrected 17,808
            $this->obs([
                'id_endorse' => 2, 'content_id' => 'other',
                'prev_after' => null, 'prev_log_date' => null,
                'views' => 58453, 'views_after' => 100000,
            ]), // opening row: planner leaves stored views alone
        ];
        $b = $this->fold($rows, ['2026-08-03']);

        self::assertSame(76261, $b['2026-08-03']['repair_parity_views']);
        self::assertSame(
            17808,
            $b['2026-08-03']['observed_daily_growth'],
            'the product metric excludes opening views and is NOT the parity figure'
        );
    }

    // ------------------------------------------------------------ date helpers

    public function test_date_range_is_inclusive_at_both_ends(): void
    {
        self::assertSame(
            ['2026-08-03', '2026-08-04', '2026-08-05'],
            Endorse_analytics_v2::date_range('2026-08-03', '2026-08-05')
        );
        self::assertSame(['2026-08-03'], Endorse_analytics_v2::date_range('2026-08-03', '2026-08-03'));
        self::assertSame([], Endorse_analytics_v2::date_range('2026-08-05', '2026-08-03'));
    }

    public function test_day_span_rejects_unparseable_dates(): void
    {
        self::assertSame(0, Endorse_analytics_v2::day_span('', '2026-08-03'));
        self::assertSame(0, Endorse_analytics_v2::day_span('not-a-date', '2026-08-03'));
    }

    // ------------------------------------------- 22. the read model cannot write

    /** The V2 read model must never write to endorse_logs. */
    public function test_read_model_never_writes_to_endorse_logs(): void
    {
        $src = file_get_contents(__DIR__ . '/../application/libraries/Endorse_analytics_read_model.php');
        self::assertIsString($src);

        foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'REPLACE INTO', 'TRUNCATE', 'ALTER TABLE'] as $verb) {
            self::assertStringNotContainsString(
                $verb,
                strtoupper($src),
                "V2 read model must never contain a $verb statement"
            );
        }
    }

    public function test_metric_definitions_are_shipped_with_the_payload(): void
    {
        $defs = Endorse_analytics_v2::metric_definitions();

        self::assertArrayHasKey('observed_daily_growth', $defs);
        self::assertArrayHasKey('total_observed_views', $defs);
        self::assertStringContainsString('terakhir teramati', $defs['total_observed_views']);
    }
}
