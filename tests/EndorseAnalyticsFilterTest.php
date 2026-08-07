<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_analytics_v2.php';

/**
 * Filter-contract tests for the V2 read model.
 *
 * The V2 endpoint must select the SAME population the legacy chart selects, so
 * that a rollout diff is attributable purely to the arithmetic change. These
 * tests pin the semantics documented in the Phase 1 audit.
 */
final class EndorseAnalyticsFilterTest extends TestCase
{
    /** Stand-in for CI_DB_driver::escape_str. */
    private function escaper(): callable
    {
        return function (string $v): string {
            return str_replace("'", "''", $v);
        };
    }

    private function build(array $get): array
    {
        return Endorse_analytics_v2::build_filters($get, $this->escaper());
    }

    private function whereSql(array $get): string
    {
        return implode(' AND ', $this->build($get)['where']);
    }

    // ------------------------------------------------ 12. PIC exact vs substring

    public function test_pic_multiselect_is_an_exact_match(): void
    {
        $sql = $this->whereSql(['pic' => ['Fazra', 'Sarah']]);

        self::assertStringContainsString("e.pic IN ('Fazra','Sarah')", $sql);
        self::assertStringNotContainsString('LIKE', $sql, 'the pic[] filter is exact, never substring');
    }

    public function test_pic_keyword_search_is_a_substring_match(): void
    {
        $sql = $this->whereSql(['keyword_category' => 'PIC', 'keyword' => 'Sarah']);

        self::assertStringContainsString("e.pic LIKE '%Sarah%'", $sql);
    }

    /**
     * Production has a PIC literally named `sarah.bka`. The exact filter and the
     * keyword search therefore select genuinely different populations, and V2
     * preserves both rather than quietly unifying them.
     */
    public function test_exact_and_substring_pic_filters_are_not_interchangeable(): void
    {
        $exact = $this->whereSql(['pic' => ['Sarah']]);
        $substr = $this->whereSql(['keyword_category' => 'PIC', 'keyword' => 'Sarah']);

        self::assertNotSame($exact, $substr);
        self::assertStringContainsString("IN ('Sarah')", $exact);
        self::assertStringContainsString("LIKE '%Sarah%'", $substr);
    }

    public function test_pic_values_are_escaped(): void
    {
        $sql = $this->whereSql(['pic' => ["O'Brien"]]);
        self::assertStringContainsString("e.pic IN ('O''Brien')", $sql);
    }

    public function test_blank_pic_entries_are_dropped(): void
    {
        $sql = $this->whereSql(['pic' => ['Fazra', '', null]]);
        self::assertStringContainsString("e.pic IN ('Fazra')", $sql);
    }

    // --------------------------------------------------------- date inclusivity

    public function test_date_range_is_inclusive_and_preserved(): void
    {
        $f = $this->build(['start_date' => '2026-08-01', 'until_date' => '2026-08-05']);

        self::assertSame('2026-08-01', $f['from']);
        self::assertSame('2026-08-05', $f['until']);
        self::assertCount(5, Endorse_analytics_v2::date_range($f['from'], $f['until']));
    }

    public function test_reversed_date_range_is_normalised_rather_than_returning_nothing(): void
    {
        $f = $this->build(['start_date' => '2026-08-05', 'until_date' => '2026-08-01']);

        self::assertSame('2026-08-01', $f['from']);
        self::assertSame('2026-08-05', $f['until']);
    }

    public function test_malformed_dates_fall_back_to_the_default_window(): void
    {
        $f = $this->build(['start_date' => 'garbage', 'until_date' => '']);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $f['from']);
        self::assertSame(date('Y-m-d'), $f['until']);
    }

    // ------------------------------------------------------- 9. internal/external

    public function test_internal_filter_requires_the_campaign_join(): void
    {
        $f = $this->build(['endorse_category' => 'internal']);

        self::assertContains('c.is_internal = 1', $f['where']);
        self::assertTrue($f['needs_campaign_join']);
    }

    public function test_external_filter_requires_the_campaign_join(): void
    {
        $f = $this->build(['endorse_category' => 'external']);

        self::assertContains('c.is_internal = 0', $f['where']);
        self::assertTrue($f['needs_campaign_join']);
    }

    public function test_unknown_category_applies_no_internal_filter(): void
    {
        $f = $this->build(['endorse_category' => 'anything-else']);

        self::assertStringNotContainsString('is_internal', implode(' ', $f['where']));
        self::assertFalse($f['needs_campaign_join']);
    }

    // ------------------------------------------------------------ campaign scope

    public function test_single_campaign_scope(): void
    {
        self::assertStringContainsString('e.id_campaign = 27', $this->whereSql(['id_campaign' => 27]));
    }

    public function test_multi_campaign_scope_takes_precedence(): void
    {
        $sql = $this->whereSql(['id_campaign' => 27, 'ids_campaign' => [27, 39]]);

        self::assertStringContainsString('e.id_campaign IN (27,39)', $sql);
        self::assertStringNotContainsString('e.id_campaign = 27', $sql);
    }

    public function test_campaign_and_endorse_ids_are_coerced_to_integers(): void
    {
        $sql = $this->whereSql(['ids_campaign' => ['27', '39abc', '0', 'x'], 'ids' => '11677,11678']);

        self::assertStringContainsString('e.id_campaign IN (27,39)', $sql);
        self::assertStringContainsString('l.id_endorse IN (11677,11678)', $sql);
    }

    public function test_duplicate_campaign_ids_are_collapsed(): void
    {
        self::assertStringContainsString(
            'e.id_campaign IN (27)',
            $this->whereSql(['ids_campaign' => [27, 27, 27]])
        );
    }

    // ------------------------------------------------------------ other filters

    public function test_multi_value_status_filters_use_in(): void
    {
        $sql = $this->whereSql(['endorse_status' => 'Selesai,Berjalan']);
        self::assertStringContainsString("e.status_endorse IN ('Selesai','Berjalan')", $sql);
    }

    public function test_link_upload_status_filters(): void
    {
        self::assertStringContainsString("e.link_upload != ''", $this->whereSql(['status' => 'Ada Link Upload']));
        self::assertStringContainsString("e.link_upload = ''", $this->whereSql(['status' => 'Tidak Ada Link Upload']));
        self::assertStringContainsString('e.is_fyp = 1', $this->whereSql(['status' => 'FYP']));
    }

    public function test_brand_platform_and_product_filters(): void
    {
        $sql = $this->whereSql([
            'brand' => 'Forbes',
            'platform' => 'Tiktok',
            'product' => ['Serum', 'Toner'],
        ]);

        self::assertStringContainsString("e.brand = 'Forbes'", $sql);
        self::assertStringContainsString("e.platform = 'Tiktok'", $sql);
        self::assertStringContainsString("e.product IN ('Serum','Toner')", $sql);
    }

    public function test_unknown_keyword_category_is_ignored(): void
    {
        $sql = $this->whereSql(['keyword_category' => 'Nonsense', 'keyword' => 'x']);
        self::assertStringNotContainsString('LIKE', $sql);
    }

    public function test_no_filters_produces_an_empty_where_list(): void
    {
        self::assertSame([], $this->build([])['where']);
    }
}
