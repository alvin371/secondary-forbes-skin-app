<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Endorse_analytics_v2 — pure metric core for the V2 analytics read model.
 *
 * Deliberately free of database access so every metric rule can be unit-tested
 * in isolation, mirroring the convention established by Endorse_repair_plan.
 *
 * The historical `endorse_logs.views` column is unreliable for 2026-07-27 ..
 * 2026-08-05 because daily rows were mutable. `views_after` — the observed
 * cumulative snapshot — was never corrupted and is the only evidence source
 * used here. Nothing in this class or its callers writes to endorse_logs.
 *
 * Metric convention:
 *
 *     observed_daily_growth(D) = SUM over content of
 *         max(0, closing(c, D) - closing(c, previous trustworthy observation))
 *
 * Opening views (the first trustworthy observation for a content) are reported
 * separately and are NEVER folded into growth: views that already existed when
 * we first looked were not earned on that day.
 */
class Endorse_analytics_v2
{
    /** Predecessor exists and is the immediately preceding calendar day. */
    const OBS_GROWTH = 'growth';
    /** Predecessor exists but is more than one day old — growth spans a gap. */
    const OBS_GAP = 'gap';
    /** First trustworthy observation for this content. */
    const OBS_OPENING = 'opening';
    /** Cumulative went backwards versus the predecessor. */
    const OBS_NEGATIVE = 'negative';
    /** No observation on this date; previous close carried for the line only. */
    const OBS_CARRIED = 'carried';

    const COMPLETE = 'complete';
    const PARTIAL = 'partial';
    const INSUFFICIENT = 'insufficient';

    /** Above this unresolved ratio a date is 'insufficient' rather than 'partial'. */
    const UNRESOLVED_PARTIAL_MAX = 0.34;

    /** Bumped whenever the arithmetic changes; surfaced in the API as meta.calculation_version. */
    const CALCULATION_VERSION = 'v2.0.0';

    /** Population modes. 'raw' reproduces today's row-level counts. */
    const POPULATION_RAW = 'raw';
    const POPULATION_CANONICAL = 'canonical';

    // ------------------------------------------------------------ observation

    /**
     * Classify a single daily observation against its authoritative predecessor.
     *
     * The gate order intentionally mirrors Endorse_repair_plan::classify_row()
     * so that for any row that planner calls `safe_repair`, the growth computed
     * here equals its `proposed_views` exactly. That equality is the historical
     * validation oracle and is asserted in the test suite.
     *
     * @param array $row Keys: log_date, views_after, prev_after (null when absent),
     *                   prev_log_date (null when absent).
     * @return array {class, growth, opening, gap_days, negative_views}
     */
    public static function classify_observation(array $row): array
    {
        $closing = intval($row['views_after'] ?? 0);
        $prev = array_key_exists('prev_after', $row) && $row['prev_after'] !== null
            ? intval($row['prev_after'])
            : null;

        $out = [
            'class' => self::OBS_GROWTH,
            'growth' => 0,
            'opening' => 0,
            'gap_days' => 0,
            'negative_views' => 0,
        ];

        // No predecessor: these views existed before we were watching. They are
        // opening views, not growth. Never silently assigned to a daily bar.
        if ($prev === null) {
            $out['class'] = self::OBS_OPENING;
            $out['opening'] = $closing;
            return $out;
        }

        // Cumulative views cannot legitimately fall. Preserve the anomaly with
        // its signed magnitude rather than clamping it out of existence; the
        // growth contribution is floored at 0 so a bar is never negative.
        if ($closing < $prev) {
            $out['class'] = self::OBS_NEGATIVE;
            $out['negative_views'] = $closing - $prev;
            return $out;
        }

        $out['growth'] = $closing - $prev;
        $out['gap_days'] = self::day_span(
            isset($row['prev_log_date']) ? strval($row['prev_log_date']) : '',
            strval($row['log_date'] ?? '')
        );

        // A multi-day gap means the increase accrued over several days. It is
        // reported, but the date is marked incomplete so no single calendar day
        // silently claims growth it did not earn.
        if ($out['gap_days'] > 1) {
            $out['class'] = self::OBS_GAP;
        }

        return $out;
    }

    /** Whole days between two Y-m-d dates; 0 when either is unparseable. */
    public static function day_span(string $from, string $to): int
    {
        if (!self::is_date($from) || !self::is_date($to)) {
            return 0;
        }
        $a = strtotime($from . ' 00:00:00');
        $b = strtotime($to . ' 00:00:00');
        if ($a === false || $b === false) {
            return 0;
        }
        return intval(round(($b - $a) / 86400));
    }

    private static function is_date(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    // ------------------------------------------------------------- date folding

    /**
     * Fold observation rows into per-date buckets.
     *
     * Growth is computed per content FIRST and only summed afterwards. Whole-
     * campaign totals are never differenced against each other — doing so would
     * attribute a change in campaign population to view growth.
     *
     * @param array $rows Ordered by (id_endorse, log_date). Each row needs:
     *                    id_endorse, log_date, views, views_after, prev_after,
     *                    prev_log_date, content_id.
     * @param array $ctx  {dates: string[], unresolved_by_date: array<string,int>,
     *                     duplicate_content_ids: array<string,true>,
     *                     repair_parity: array<int,int> keyed by row id,
     *                     carried_by_date: array<string,array>}
     * @return array<string,array> keyed by date, in $ctx['dates'] order.
     */
    public static function fold_dates(array $rows, array $ctx = []): array
    {
        $dates = $ctx['dates'] ?? [];
        $unresolvedByDate = $ctx['unresolved_by_date'] ?? [];
        $duplicates = $ctx['duplicate_content_ids'] ?? [];
        $carriedByDate = $ctx['carried_by_date'] ?? [];

        $buckets = [];
        foreach ($dates as $d) {
            $buckets[$d] = self::empty_bucket($d);
        }

        // Canonical accumulation: one content id contributes once per date.
        // Tracked separately so the raw and canonical populations are both
        // available from a single pass.
        $canonicalSeen = [];

        foreach ($rows as $row) {
            $date = strval($row['log_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($buckets[$date])) {
                $buckets[$date] = self::empty_bucket($date);
            }

            $obs = self::classify_observation($row);
            $b =& $buckets[$date];

            $closing = intval($row['views_after'] ?? 0);
            $contentId = strval($row['content_id'] ?? '');

            $b['included_post_count']++;
            $b['total_observed_views'] += $closing;
            $b['observed_daily_growth'] += $obs['growth'];
            $b['opening_views'] += $obs['opening'];

            if ($obs['class'] === self::OBS_GAP) {
                $b['growth_since_last_observation'] += $obs['growth'];
                $b['gap_row_count']++;
            }
            if ($obs['class'] === self::OBS_NEGATIVE) {
                $b['negative_anomaly_count']++;
                $b['negative_anomaly_views'] += $obs['negative_views'];
            }
            if ($contentId !== '' && isset($duplicates[$contentId])) {
                $b['_duplicate_ids'][$contentId] = true;
            }

            // repair_parity_views reproduces the historical repair planner's
            // "proposed total": stored views everywhere except safe rows. It is
            // a diagnostic for validating against the repair preview and is
            // never presented as the product metric.
            $b['repair_parity_views'] += self::repair_parity_contribution($row, $obs);

            // Canonical view: first row wins for a given (date, content id).
            $canonKey = $date . '|' . $contentId;
            if ($contentId === '' || !isset($canonicalSeen[$canonKey])) {
                $canonicalSeen[$canonKey] = true;
                $b['canonical']['included_post_count']++;
                $b['canonical']['total_observed_views'] += $closing;
                $b['canonical']['observed_daily_growth'] += $obs['growth'];
                $b['canonical']['opening_views'] += $obs['opening'];
            }
        }
        unset($b);

        foreach ($buckets as $date => $bucket) {
            $carried = $carriedByDate[$date] ?? ['count' => 0, 'views' => 0];
            $buckets[$date]['carried_forward_count'] = intval($carried['count']);
            $buckets[$date]['carried_forward_views'] = intval($carried['views']);
            // The cumulative line may carry a previous close forward so it does
            // not crater when a poll is missed. It is labelled as carried, never
            // presented as an exact observation for that date.
            $buckets[$date]['total_observed_views'] += intval($carried['views']);
            $buckets[$date]['canonical']['total_observed_views'] += intval($carried['views']);

            $buckets[$date]['unresolved_post_count'] = intval($unresolvedByDate[$date] ?? 0);
            $buckets[$date]['duplicate_group_count'] = count($buckets[$date]['_duplicate_ids']);
            unset($buckets[$date]['_duplicate_ids']);

            $buckets[$date]['data_completeness'] = self::completeness(
                $buckets[$date]['included_post_count'],
                $buckets[$date]['unresolved_post_count'],
                $buckets[$date]['gap_row_count'],
                $buckets[$date]['carried_forward_count']
            );

            // An entirely unobserved date must not render as a confident zero.
            if ($buckets[$date]['included_post_count'] === 0) {
                $buckets[$date]['observed_daily_growth'] = null;
                $buckets[$date]['canonical']['observed_daily_growth'] = null;
            }
        }

        return $buckets;
    }

    /**
     * A row's contribution to the repair-planner parity total: the corrected
     * value on rows the planner would call safe, the stored value elsewhere.
     */
    private static function repair_parity_contribution(array $row, array $obs): int
    {
        $stored = intval($row['views'] ?? 0);
        if ($obs['class'] !== self::OBS_GROWTH && $obs['class'] !== self::OBS_GAP) {
            return $stored;
        }
        if (!empty($row['is_duplicate']) || !empty($row['is_unresolved'])) {
            return $stored;
        }
        return $obs['growth'];
    }

    private static function empty_bucket(string $date): array
    {
        return [
            'date' => $date,
            'observed_daily_growth' => 0,
            'total_observed_views' => 0,
            'opening_views' => 0,
            'growth_since_last_observation' => 0,
            'included_post_count' => 0,
            'unresolved_post_count' => 0,
            'duplicate_group_count' => 0,
            'negative_anomaly_count' => 0,
            'negative_anomaly_views' => 0,
            'gap_row_count' => 0,
            'carried_forward_count' => 0,
            'carried_forward_views' => 0,
            'repair_parity_views' => 0,
            'data_completeness' => self::INSUFFICIENT,
            'canonical' => [
                'observed_daily_growth' => 0,
                'total_observed_views' => 0,
                'opening_views' => 0,
                'included_post_count' => 0,
            ],
            '_duplicate_ids' => [],
        ];
    }

    // ------------------------------------------------------------- completeness

    /**
     * Data completeness for one date.
     *
     * complete     — every active post in scope was observed, no gaps, no carry.
     * partial      — some posts unobserved, but the observed majority is usable.
     * insufficient — too little was observed to characterise the date at all.
     */
    public static function completeness(int $included, int $unresolved, int $gaps, int $carried): string
    {
        if ($included <= 0) {
            return self::INSUFFICIENT;
        }

        $total = $included + $unresolved;
        $ratio = $total > 0 ? $unresolved / $total : 0.0;

        if ($ratio > self::UNRESOLVED_PARTIAL_MAX) {
            return self::INSUFFICIENT;
        }
        if ($ratio == 0.0 && $gaps === 0 && $carried === 0) {
            return self::COMPLETE;
        }
        return self::PARTIAL;
    }

    // ----------------------------------------------------------------- summary

    /**
     * Roll per-date buckets up into a period summary.
     *
     * total_views_at_end_date is the LAST date's cumulative total, not a sum of
     * daily totals — summing cumulative snapshots across dates would multiply
     * the same views by the number of days in range.
     */
    public static function summarize(array $dates, string $population = self::POPULATION_RAW): array
    {
        $useCanonical = $population === self::POPULATION_CANONICAL;

        $summary = [
            'total_views_at_end_date' => 0,
            'growth_in_selected_period' => 0,
            'opening_views_in_selected_period' => 0,
            'growth_since_last_observation' => 0,
            'included_post_count' => 0,
            'unresolved_post_count' => 0,
            'duplicate_group_count' => 0,
            'negative_anomaly_count' => 0,
            'carried_forward_count' => 0,
            'repair_parity_views' => 0,
            'data_completeness' => self::INSUFFICIENT,

            // Per-day context for the dashboard cards. Derived here rather than
            // in the view so the cards can never drift from the summary.
            'observed_day_count' => 0,
            'average_daily_growth' => 0,
            'peak_daily_growth' => 0,
            'peak_daily_growth_date' => null,
            'latest_observed_date' => null,
            'previous_observed_date' => null,
            'latest_daily_growth' => 0,
            'latest_opening_views' => 0,
            'total_views_change_vs_previous_day' => null,
            'completeness_day_counts' => [
                self::COMPLETE => 0,
                self::PARTIAL => 0,
                self::INSUFFICIENT => 0,
            ],
        ];

        $lastObserved = null;
        $worst = self::COMPLETE;
        $previousTotal = null;

        foreach ($dates as $d) {
            $scope = $useCanonical ? $d['canonical'] : $d;

            $summary['growth_in_selected_period'] += intval($scope['observed_daily_growth'] ?? 0);
            $summary['opening_views_in_selected_period'] += intval($scope['opening_views'] ?? 0);
            $summary['growth_since_last_observation'] += intval($d['growth_since_last_observation'] ?? 0);
            $summary['negative_anomaly_count'] += intval($d['negative_anomaly_count'] ?? 0);
            $summary['carried_forward_count'] += intval($d['carried_forward_count'] ?? 0);
            $summary['repair_parity_views'] += intval($d['repair_parity_views'] ?? 0);

            $summary['completeness_day_counts'][strval($d['data_completeness'] ?? self::INSUFFICIENT)]++;

            if (intval($d['included_post_count'] ?? 0) > 0) {
                // Carry the previous observed day's total before overwriting, so
                // the Total Views card can show a day-over-day change.
                $previousTotal = $summary['total_views_at_end_date'];
                $summary['previous_observed_date'] = $summary['latest_observed_date'];

                $lastObserved = $d;
                // The scope's own post/unresolved counts describe the most
                // recent observed day, matching what the Total Views card says.
                $summary['included_post_count'] = intval($scope['included_post_count'] ?? 0);
                $summary['unresolved_post_count'] = intval($d['unresolved_post_count'] ?? 0);
                $summary['total_views_at_end_date'] = intval($scope['total_observed_views'] ?? 0);

                $summary['observed_day_count']++;
                $summary['latest_observed_date'] = strval($d['date'] ?? '');
                $summary['latest_daily_growth'] = intval($scope['observed_daily_growth'] ?? 0);
                $summary['latest_opening_views'] = intval($scope['opening_views'] ?? 0);

                $growth = intval($scope['observed_daily_growth'] ?? 0);
                if ($summary['peak_daily_growth_date'] === null || $growth > $summary['peak_daily_growth']) {
                    $summary['peak_daily_growth'] = $growth;
                    $summary['peak_daily_growth_date'] = strval($d['date'] ?? '');
                }
            }

            $summary['duplicate_group_count'] = max(
                $summary['duplicate_group_count'],
                intval($d['duplicate_group_count'] ?? 0)
            );
            $worst = self::worse_completeness($worst, strval($d['data_completeness'] ?? self::INSUFFICIENT));
        }

        $summary['data_completeness'] = $lastObserved === null ? self::INSUFFICIENT : $worst;

        // Averaged over OBSERVED days only. Dividing by calendar days would let
        // a day we never polled drag the average down as if growth were zero.
        if ($summary['observed_day_count'] > 0) {
            $summary['average_daily_growth'] = intval(round(
                $summary['growth_in_selected_period'] / $summary['observed_day_count']
            ));
        }

        // Change in the cumulative total between the last two observed days.
        // This is NOT daily growth: it also contains the opening views of any
        // content that entered the population, i.e. growth + opening.
        if ($previousTotal !== null && $summary['previous_observed_date'] !== null) {
            $summary['total_views_change_vs_previous_day'] =
                $summary['total_views_at_end_date'] - $previousTotal;
        }

        return $summary;
    }

    /** Completeness is reported at its weakest link across the period. */
    public static function worse_completeness(string $a, string $b): string
    {
        $rank = [self::COMPLETE => 0, self::PARTIAL => 1, self::INSUFFICIENT => 2];
        return ($rank[$b] ?? 2) > ($rank[$a] ?? 2) ? $b : $a;
    }

    // ----------------------------------------------------------------- filters

    /**
     * Build the SQL filter fragments for the V2 read model from request input.
     *
     * Pure so the PIC and date-range semantics are testable. Deliberately
     * reproduces the legacy contract documented in Ajax::get_chart_campaign():
     *
     *   - `pic[]`  is an EXACT multi-select   -> endorse.pic IN (...)
     *   - `keyword` with keyword_category=PIC is a SUBSTRING search -> LIKE
     *   - date ranges are INCLUSIVE at both ends
     *
     * @param array    $get    Request parameters.
     * @param callable $escape Value escaper, e.g. [$db, 'escape_str'].
     * @return array {where: string[], from: string, until: string, needs_campaign_join: bool}
     */
    public static function build_filters(array $get, callable $escape): array
    {
        $q = function ($v) use ($escape) {
            return "'" . $escape(strval($v)) . "'";
        };

        $where = [];
        $needsCampaignJoin = false;

        $from = self::is_date(strval($get['start_date'] ?? '')) ? strval($get['start_date']) : '';
        $until = self::is_date(strval($get['until_date'] ?? '')) ? strval($get['until_date']) : '';
        if ($from === '') {
            $from = date('Y-m-d', strtotime('-31 days'));
        }
        if ($until === '') {
            $until = date('Y-m-d');
        }
        // A reversed range would silently return nothing; normalise instead.
        if ($from > $until) {
            [$from, $until] = [$until, $from];
        }

        $campaignIds = self::int_list($get['ids_campaign'] ?? null);
        if (!empty($campaignIds)) {
            $where[] = 'e.id_campaign IN (' . implode(',', $campaignIds) . ')';
        } elseif (!empty($get['id_campaign'])) {
            $where[] = 'e.id_campaign = ' . intval($get['id_campaign']);
        }

        $ids = self::int_list($get['ids'] ?? null);
        if (!empty($ids)) {
            $where[] = 'l.id_endorse IN (' . implode(',', $ids) . ')';
        }

        // Exact multi-select PIC — matches the legacy `pic[]` contract.
        $pic = $get['pic'] ?? null;
        if (!empty($pic)) {
            if (!is_array($pic)) {
                $pic = [$pic];
            }
            $pic = array_values(array_filter($pic, function ($v) {
                return $v !== '' && $v !== null;
            }));
            if (!empty($pic)) {
                $where[] = 'e.pic IN (' . implode(',', array_map($q, $pic)) . ')';
            }
        }

        $product = $get['product'] ?? null;
        if (!empty($product)) {
            if (!is_array($product)) {
                $product = [$product];
            }
            $product = array_values(array_filter($product, function ($v) {
                return $v !== '' && $v !== null;
            }));
            if (!empty($product)) {
                $where[] = 'e.product IN (' . implode(',', array_map($q, $product)) . ')';
            }
        }

        if (!empty($get['brand'])) {
            $where[] = 'e.brand = ' . $q($get['brand']);
        }
        if (!empty($get['platform'])) {
            $where[] = 'e.platform = ' . $q($get['platform']);
        }
        if (!empty($get['status_data'])) {
            $where[] = 'e.status = ' . $q($get['status_data']);
        }

        $category = $get['endorse_category'] ?? null;
        if ($category === 'internal') {
            $where[] = 'c.is_internal = 1';
            $needsCampaignJoin = true;
        } elseif ($category === 'external') {
            $where[] = 'c.is_internal = 0';
            $needsCampaignJoin = true;
        }

        $status = $get['status'] ?? null;
        if ($status === 'Ada Link Upload') {
            $where[] = "e.link_upload != ''";
        } elseif ($status === 'Tidak Ada Link Upload') {
            $where[] = "e.link_upload = ''";
        } elseif ($status === 'FYP') {
            $where[] = 'e.is_fyp = 1';
        }

        foreach (['endorse_status' => 'status_endorse', 'status_payment' => 'status_payment'] as $key => $col) {
            if (empty($get[$key])) {
                continue;
            }
            $vals = is_array($get[$key]) ? $get[$key] : explode(',', strval($get[$key]));
            $vals = array_values(array_filter($vals, function ($v) {
                return $v !== '' && $v !== null;
            }));
            if (!empty($vals)) {
                $where[] = 'e.' . $col . ' IN (' . implode(',', array_map($q, $vals)) . ')';
            }
        }

        // Substring keyword search — a deliberately different population from
        // the exact `pic[]` filter above. Both are preserved as-is.
        $keyword = strval($get['keyword'] ?? '');
        if ($keyword !== '') {
            $cols = [
                'Nama Creator' => 'e.nama_creator',
                'Link Upload' => 'e.link_upload',
                'PIC' => 'e.pic',
                'Platform' => 'e.platform',
                'Task' => 'e.task',
                'Keterangan' => 'e.`desc`',
            ];
            $cat = strval($get['keyword_category'] ?? 'Nama Creator');
            if (isset($cols[$cat])) {
                $where[] = $cols[$cat] . ' LIKE ' . $q('%' . $keyword . '%');
            }
        }

        return [
            'where' => $where,
            'from' => $from,
            'until' => $until,
            'needs_campaign_join' => $needsCampaignJoin,
        ];
    }

    /** Coerce a scalar or array of ids into a list of positive ints. */
    private static function int_list($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $parts = is_array($value) ? $value : explode(',', strval($value));
        $out = [];
        foreach ($parts as $p) {
            $n = intval($p);
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /** Inclusive Y-m-d range, matching the legacy createRange() contract. */
    public static function date_range(string $from, string $until): array
    {
        if (!self::is_date($from) || !self::is_date($until) || $from > $until) {
            return [];
        }
        $out = [];
        $cursor = strtotime($from);
        $end = strtotime($until);
        while ($cursor <= $end) {
            $out[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }
        return $out;
    }

    /** Human-readable definitions shipped in the API so the UI cannot drift. */
    public static function metric_definitions(): array
    {
        return [
            'observed_daily_growth' => 'Difference between successive trustworthy closing observations per content, summed across content. Opening views are excluded.',
            'total_observed_views' => 'Sum of the last observed cumulative views for canonical content in scope. Total views terakhir teramati — not an exact midnight TikTok total.',
            'opening_views' => 'Views already present at the first trustworthy observation of a content. Not growth earned on that day.',
            'growth_since_last_observation' => 'Growth accrued over a multi-day gap between observations; not attributable to a single calendar date.',
            'unresolved_post_count' => 'Active posts with no trustworthy provider observation for the date. Not zero-view posts.',
            'repair_parity_views' => 'Diagnostic only: legacy stored views with repair-safe rows substituted. Reproduces the historical repair preview total.',
        ];
    }
}
