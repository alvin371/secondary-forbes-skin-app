<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Endorse_repair_plan — pure planning logic for the historical daily-delta repair.
 *
 * Deliberately free of database access so the classification and arithmetic can
 * be unit-tested in isolation. The CLI controller supplies rows (already joined
 * with their predecessor) and consumes the resulting plan.
 *
 * Metric being repaired (system-convention observed daily growth):
 *
 *     observed_daily_growth(D) = views_after(D) - views_after(previous authoritative row)
 *
 * This is NOT exact real-world midnight growth; there are no midnight snapshots.
 * `views_after` is the observed cumulative snapshot and is never modified.
 */
class Endorse_repair_plan
{
    /** Rows eligible for automatic write. */
    const CAT_SAFE = 'safe_repair';
    /** Stored values already equal the proposed values. */
    const CAT_ALREADY_CORRECT = 'already_correct';
    /** No authoritative predecessor — opening-view policy required. */
    const CAT_OPENING_DECISION = 'opening_decision';
    /** Cumulative went backwards; never auto-repaired. */
    const CAT_NEGATIVE = 'negative_anomaly';
    /** Result depends on the unresolved duplicate uniqueness contract. */
    const CAT_DUPLICATE_SENSITIVE = 'duplicate_sensitive';
    /** No trustworthy provider snapshot exists for this content. */
    const CAT_UNRESOLVED = 'provider_unresolved';
    /** endorse_logs.id_campaign disagrees with endorse.id_campaign. */
    const CAT_OWNERSHIP = 'ownership_anomaly';

    /** Earliest date the historical repair may touch. */
    const WINDOW_MIN = '2026-07-27';
    /** Latest date the historical repair may touch — 2026-08-06+ is forward-fixed data. */
    const WINDOW_MAX = '2026-08-05';

    /**
     * Classify one daily-log row and compute its proposed values.
     *
     * @param array $row Keys: id, id_endorse, id_campaign, log_date, views_before,
     *                   views, views_after, prev_after (null when absent),
     *                   endorse_campaign, is_duplicate, is_unresolved.
     * @return array {category, proposed_views_before, proposed_views, delta_change, reason}
     */
    public static function classify_row(array $row): array
    {
        $viewsAfter  = intval($row['views_after'] ?? 0);
        $viewsBefore = intval($row['views_before'] ?? 0);
        $views       = intval($row['views'] ?? 0);
        $prevAfter   = array_key_exists('prev_after', $row) && $row['prev_after'] !== null
            ? intval($row['prev_after'])
            : null;

        $plan = [
            'category' => self::CAT_SAFE,
            'proposed_views_before' => $viewsBefore,
            'proposed_views' => $views,
            'delta_change' => 0,
            'reason' => '',
        ];

        // Integrity gates first — these disqualify a row regardless of arithmetic.
        if (!self::is_within_window(strval($row['log_date'] ?? ''))) {
            $plan['category'] = self::CAT_OWNERSHIP;
            $plan['reason'] = 'date outside repair window';
            return $plan;
        }

        if (intval($row['id_campaign'] ?? 0) !== intval($row['endorse_campaign'] ?? 0)) {
            $plan['category'] = self::CAT_OWNERSHIP;
            $plan['reason'] = 'endorse_logs.id_campaign != endorse.id_campaign';
            return $plan;
        }

        if (!empty($row['is_unresolved'])) {
            $plan['category'] = self::CAT_UNRESOLVED;
            $plan['reason'] = 'provider-unresolvable content';
            return $plan;
        }

        if (!empty($row['is_duplicate'])) {
            $plan['category'] = self::CAT_DUPLICATE_SENSITIVE;
            $plan['reason'] = 'duplicate content-id group; uniqueness contract undecided';
            return $plan;
        }

        if ($prevAfter === null) {
            $plan['category'] = self::CAT_OPENING_DECISION;
            $plan['reason'] = 'no authoritative predecessor; opening-view rule required';
            return $plan;
        }

        if ($viewsAfter < $prevAfter) {
            $plan['category'] = self::CAT_NEGATIVE;
            $plan['reason'] = 'cumulative views decreased vs predecessor';
            return $plan;
        }

        $proposedBefore = $prevAfter;
        $proposedViews  = $viewsAfter - $prevAfter;

        $plan['proposed_views_before'] = $proposedBefore;
        $plan['proposed_views'] = $proposedViews;
        $plan['delta_change'] = $proposedViews - $views;

        if ($proposedBefore === $viewsBefore && $proposedViews === $views) {
            $plan['category'] = self::CAT_ALREADY_CORRECT;
            $plan['reason'] = 'stored values already match the convention';
            return $plan;
        }

        $plan['reason'] = 'restore start-of-day baseline from previous authoritative close';
        return $plan;
    }

    /** Guard: the repair may only ever touch 2026-07-27 .. 2026-08-05. */
    public static function is_within_window(string $logDate): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
            return false;
        }
        return $logDate >= self::WINDOW_MIN && $logDate <= self::WINDOW_MAX;
    }

    /**
     * Deterministic checksum over the rows a run intends to touch. Recomputed
     * immediately before apply; any drift aborts the run.
     */
    public static function checksum(array $rows): string
    {
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = implode(':', [
                intval($r['id']),
                intval($r['views_before']),
                intval($r['views']),
                intval($r['views_after']),
            ]);
        }
        sort($parts, SORT_STRING);
        return md5(implode('|', $parts));
    }

    /** Roll a set of classified rows up into per-category counters and totals. */
    public static function summarize(array $plans): array
    {
        $summary = [];
        foreach ($plans as $p) {
            $cat = $p['category'];
            if (!isset($summary[$cat])) {
                $summary[$cat] = ['rows' => 0, 'stored_total' => 0, 'proposed_total' => 0, 'difference' => 0];
            }
            $summary[$cat]['rows']++;
            $summary[$cat]['stored_total'] += intval($p['stored_views'] ?? $p['proposed_views'] - $p['delta_change']);
            $summary[$cat]['proposed_total'] += intval($p['proposed_views']);
            $summary[$cat]['difference'] += intval($p['delta_change']);
        }
        return $summary;
    }
}
