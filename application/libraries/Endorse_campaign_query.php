<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Query helpers for the /endorse-campaign list (index count + item page).
 * Pure functions so they can be unit-tested without a DB.
 */
class Endorse_campaign_query
{
    const PER_PAGE = 10;

    /** LIMIT offset for a 1-based page number; anything < 1 or non-numeric is page 1. */
    public static function offset($page): int
    {
        return (max(1, (int) $page) - 1) * self::PER_PAGE;
    }

    /**
     * WHERE clause shared by index() (count) and item() (page).
     * start_at is compared as a range instead of DATE(start_at) so the index can be used.
     */
    public static function build_where(array $get, callable $esc, string $today): string
    {
        $start_date = !empty($get['start_date']) ? $get['start_date'] : date('Y-m-d', strtotime($today . ' -1 years'));
        $until_date = !empty($get['until_date']) ? $get['until_date'] : date('Y-m-d', strtotime($today . ' +2 years'));
        $until_next = date('Y-m-d', strtotime($until_date . ' +1 day'));

        $where = "start_at >= '" . $esc($start_date) . "' AND start_at < '" . $esc($until_next) . "'";

        if (!empty($get['brand'])) {
            $where .= " AND brand = '" . $esc($get['brand']) . "'";
        }
        if (!empty($get['marketplace'])) {
            $where .= " AND marketplace = '" . $esc($get['marketplace']) . "'";
        }

        if (!empty($get['keyword'])) {
            $kw = $esc($get['keyword']);
            $category = !empty($get['keyword_category']) ? $get['keyword_category'] : 'Judul Campaign';
            $like_columns = [
                'Judul Campaign' => 'title',
                'SKU' => 'sku',
                'Brand' => 'brand',
                'Keterangan' => 'endorse_campaign.desc',
            ];
            if (isset($like_columns[$category])) {
                $where .= " AND {$like_columns[$category]} LIKE '%{$kw}%'";
            } elseif ($category == 'Status') {
                $where .= " AND status = '{$kw}'";
            }
        }

        $where .= (isset($get['p']) && $get['p'] == 'internal') ? " AND is_internal = '1'" : " AND is_internal = '0'";

        return $where;
    }

    /** One grouped query over endorse for all campaigns on the page (replaces 3 queries per row). */
    public static function stats_sql(array $ids): string
    {
        $ids = implode(',', array_map('intval', $ids));
        return "SELECT id_campaign,
                COUNT(*) AS total_post,
                SUM(status_endorse = 'Posted Content') AS posted,
                SUM(status_endorse = 'Reject') AS rejected,
                MAX(CASE WHEN sync_at IS NOT NULL AND sync_at != '' THEN sync_at END) AS max_sync_at
            FROM endorse
            WHERE id_campaign IN ($ids)
            GROUP BY id_campaign";
    }

    /** Attach stats to each campaign row; campaigns without endorse rows get zeros. */
    public static function merge_stats(array $rows, array $stats): array
    {
        $by_id = array_column($stats, null, 'id_campaign');
        foreach ($rows as &$row) {
            $s = $by_id[$row['id']] ?? [];
            $row['total_post'] = (int) ($s['total_post'] ?? 0);
            $row['posted'] = (int) ($s['posted'] ?? 0);
            $row['rejected'] = (int) ($s['rejected'] ?? 0);
            $row['latest_update_at'] = ($s['max_sync_at'] ?? null) ?: ($row['updated_at'] ?: $row['created_at']);
        }
        return $rows;
    }
}
