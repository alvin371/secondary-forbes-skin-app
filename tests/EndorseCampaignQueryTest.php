<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_campaign_query.php';

final class EndorseCampaignQueryTest extends TestCase
{
    private function where(array $get): string
    {
        $esc = function (string $v): string {
            return str_replace("'", "\\'", $v);
        };
        return Endorse_campaign_query::build_where($get, $esc, '2026-10-04');
    }

    public function test_default_range_is_sargable_and_matches_old_window(): void
    {
        $w = $this->where([]);
        // old: DATE(start_at) >= today-1y AND DATE(start_at) <= today+2y
        self::assertStringContainsString("start_at >= '2025-10-04' AND start_at < '2028-10-05'", $w);
        self::assertStringNotContainsString('DATE(', $w);
        self::assertStringEndsWith("is_internal = '0'", $w);
    }

    public function test_filters_and_keyword_categories(): void
    {
        $w = $this->where(['start_date' => '2026-01-01', 'until_date' => '2026-01-31', 'brand' => 'FS', 'marketplace' => 'Shopee', 'p' => 'internal']);
        self::assertStringContainsString("start_at >= '2026-01-01' AND start_at < '2026-02-01'", $w);
        self::assertStringContainsString("brand = 'FS'", $w);
        self::assertStringContainsString("marketplace = 'Shopee'", $w);
        self::assertStringContainsString("is_internal = '1'", $w);

        self::assertStringContainsString("title LIKE '%abc%'", $this->where(['keyword' => 'abc']));
        self::assertStringContainsString("sku LIKE '%abc%'", $this->where(['keyword' => 'abc', 'keyword_category' => 'SKU']));
        self::assertStringContainsString("endorse_campaign.desc LIKE '%abc%'", $this->where(['keyword' => 'abc', 'keyword_category' => 'Keterangan']));
        self::assertStringContainsString("status = 'Aktif'", $this->where(['keyword' => 'Aktif', 'keyword_category' => 'Status']));
        self::assertStringNotContainsString('LIKE', $this->where(['keyword' => 'abc', 'keyword_category' => 'Unknown']));
    }

    public function test_inputs_are_escaped(): void
    {
        $w = $this->where(['brand' => "x' OR '1'='1", 'keyword' => "a'b"]);
        self::assertStringContainsString("brand = 'x\\' OR \\'1\\'=\\'1'", $w);
        self::assertStringContainsString("title LIKE '%a\\'b%'", $w);
    }

    public function test_pagination_is_10_per_page(): void
    {
        self::assertSame(10, Endorse_campaign_query::PER_PAGE);
        self::assertSame(0, Endorse_campaign_query::offset(1));
        self::assertSame(10, Endorse_campaign_query::offset('2'));
        self::assertSame(20, Endorse_campaign_query::offset(3));
        // missing / invalid page falls back to page 1
        self::assertSame(0, Endorse_campaign_query::offset(null));
        self::assertSame(0, Endorse_campaign_query::offset(0));
        self::assertSame(0, Endorse_campaign_query::offset('-5'));
        self::assertSame(0, Endorse_campaign_query::offset('abc'));
    }

    public function test_stats_sql_casts_ids(): void
    {
        $sql = Endorse_campaign_query::stats_sql(['3', '7', '1 OR 1=1']);
        self::assertStringContainsString('IN (3,7,1)', $sql);
        self::assertStringContainsString('GROUP BY id_campaign', $sql);
    }

    public function test_merge_stats_matches_old_per_row_values(): void
    {
        $rows = [
            ['id' => '1', 'updated_at' => '2026-09-01 10:00:00', 'created_at' => '2026-08-01 10:00:00'],
            ['id' => '2', 'updated_at' => null, 'created_at' => '2026-08-02 10:00:00'],
            ['id' => '3', 'updated_at' => '2026-09-03 10:00:00', 'created_at' => '2026-08-03 10:00:00'],
        ];
        $stats = [
            ['id_campaign' => '1', 'total_post' => '10', 'posted' => '6', 'rejected' => '2', 'max_sync_at' => '2026-10-01 08:00:00'],
            ['id_campaign' => '3', 'total_post' => '4', 'posted' => '0', 'rejected' => '0', 'max_sync_at' => null],
        ];
        $out = Endorse_campaign_query::merge_stats($rows, $stats);

        self::assertSame([10, 6, 2, '2026-10-01 08:00:00'], [$out[0]['total_post'], $out[0]['posted'], $out[0]['rejected'], $out[0]['latest_update_at']]);
        // no endorse rows -> zeros, falls back to created_at (old COALESCE)
        self::assertSame([0, 0, 0, '2026-08-02 10:00:00'], [$out[1]['total_post'], $out[1]['posted'], $out[1]['rejected'], $out[1]['latest_update_at']]);
        // no sync_at -> updated_at
        self::assertSame('2026-09-03 10:00:00', $out[2]['latest_update_at']);
        self::assertSame(['1', '2', '3'], array_column($out, 'id'), 'order preserved');
    }
}
