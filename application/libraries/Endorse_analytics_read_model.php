<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Endorse_analytics_v2.php';
require_once APPPATH . 'libraries/Endorse_sync.php';

/**
 * Endorse_analytics_read_model — V2 analytics read model.
 *
 * READ ONLY. This class issues SELECT statements exclusively; it exposes no
 * method that writes, and `endorse_logs` is never mutated by the V2 path. The
 * historical daily-delta defect is corrected by changing how analytics are
 * derived, not by rewriting evidence.
 *
 * Daily growth is derived from `views_after` — the observed cumulative snapshot
 * — never from the corrupted historical `views` column. All arithmetic lives in
 * Endorse_analytics_v2 so it stays unit-testable without a database.
 */
class Endorse_analytics_read_model
{
    /** @var CI_Controller */
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('mymodel');
    }

    /**
     * Build the full V2 payload for a set of request filters.
     *
     * @param array  $get        Request parameters (same names the legacy chart uses).
     * @param string $population Endorse_analytics_v2::POPULATION_RAW|POPULATION_CANONICAL
     * @return array {dates, summary, metric_definition, meta}
     */
    public function build(array $get, string $population = Endorse_analytics_v2::POPULATION_RAW): array
    {
        $filters = Endorse_analytics_v2::build_filters($get, [$this->CI->db, 'escape_str']);
        $from = $filters['from'];
        $until = $filters['until'];

        $rows = $this->load_observations($filters);
        $endorseIds = array_values(array_unique(array_map(function ($r) {
            return intval($r['id_endorse']);
        }, $rows)));

        $duplicates = $this->duplicate_content_ids($filters);
        $scopePosts = $this->scope_posts($filters);
        $unresolvedIds = $this->unresolved_endorse_ids(
            array_values(array_unique(array_merge($endorseIds, array_keys($scopePosts))))
        );

        // Annotate rows so the pure core can reproduce the repair planner's
        // category gates without touching the database itself.
        foreach ($rows as $i => $r) {
            $rows[$i]['is_duplicate'] = isset($duplicates[strval($r['content_id'])]);
            $rows[$i]['is_unresolved'] = isset($unresolvedIds[intval($r['id_endorse'])]);
        }

        $dateList = Endorse_analytics_v2::date_range($from, $until);

        $ctx = [
            'dates' => $dateList,
            'duplicate_content_ids' => $duplicates,
            'unresolved_by_date' => $this->unresolved_by_date($rows, $scopePosts, $dateList),
            'carried_by_date' => $this->carried_by_date($rows, $dateList),
        ];

        $buckets = Endorse_analytics_v2::fold_dates($rows, $ctx);
        $dates = array_values($buckets);
        $summary = Endorse_analytics_v2::summarize($dates, $population);
        $summary['duplicate_group_count'] = count($duplicates);

        return [
            'dates' => $dates,
            'summary' => $summary,
            'metric_definition' => Endorse_analytics_v2::metric_definitions(),
            'meta' => [
                'calculation_version' => Endorse_analytics_v2::CALCULATION_VERSION,
                'population' => $population,
                'from' => $from,
                'until' => $until,
                'range_inclusive' => true,
                'source_column' => 'endorse_logs.views_after',
                'observation_row_count' => count($rows),
            ],
        ];
    }

    // ------------------------------------------------------------------ loading

    /** How far before the range to look for an authoritative predecessor. */
    private function lookback_days(): int
    {
        $this->CI->load->helper('env');
        $n = intval(env('ENDORSE_ANALYTICS_V2_LOOKBACK_DAYS', '45'));
        return $n > 0 ? $n : 45;
    }

    /**
     * One bounded query returning every in-range observation alongside its
     * authoritative predecessor.
     *
     * The inner SELECT intentionally reaches BEFORE :from so LAG() finds the
     * real previous close instead of defaulting to zero — the same technique
     * used by Endorse_analytics_repair::load_rows(). LAG(log_date) is carried
     * so multi-day observation gaps are detectable rather than being silently
     * attributed to a single calendar date.
     *
     * The reach-back is bounded to lookback_days() so the scan stays bounded
     * instead of walking the entire history of every post. A predecessor older
     * than that window is treated as no predecessor at all: the observation
     * becomes an opening rather than dumping months of accumulated views onto
     * one calendar day. That is the conservative direction — it can understate
     * growth, never invent it.
     */
    private function load_observations(array $filters): array
    {
        $where = $filters['where'];
        $from = $this->CI->db->escape($filters['from']);
        $until = $this->CI->db->escape($filters['until']);
        $floor = $this->CI->db->escape(
            date('Y-m-d', strtotime($filters['from'] . ' -' . $this->lookback_days() . ' days'))
        );

        $inner = array_merge($where, [
            'l.log_date <= ' . $until,
            'l.log_date >= ' . $floor,
        ]);
        // Only trustworthy cumulative observations participate. A row with no
        // positive close is not evidence that the post has zero views.
        $inner[] = 'l.views_after > 0';

        $sql = "
            SELECT x.* FROM (
                SELECT l.id, l.id_endorse, l.log_date, l.views, l.views_before, l.views_after,
                       LAG(l.views_after) OVER w AS prev_after,
                       LAG(l.log_date)    OVER w AS prev_log_date,
                       e.id_campaign, e.pic,
                       c.is_internal,
                       REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS content_id,
                       CASE WHEN e.link_upload LIKE '%/photo/%' THEN 'photo' ELSE 'video' END AS media
                  FROM endorse_logs l
                  JOIN endorse e          ON e.id = l.id_endorse
                  JOIN endorse_campaign c ON c.id = e.id_campaign
                 WHERE " . implode(' AND ', $inner) . "
                WINDOW w AS (PARTITION BY l.id_endorse ORDER BY l.log_date)
            ) x
            WHERE x.log_date BETWEEN {$from} AND {$until}
            ORDER BY x.id_endorse ASC, x.log_date ASC
        ";

        return $this->CI->mymodel->selectWithQuery($sql) ?: [];
    }

    /**
     * Content IDs appearing on more than one endorse row inside the selected
     * population. Duplicates are surfaced and counted, never silently merged
     * or double-counted.
     */
    private function duplicate_content_ids(array $filters): array
    {
        $where = $filters['where'];
        // The duplicate scan is over `endorse`, so drop any log-table predicate.
        $where = array_values(array_filter($where, function ($w) {
            return strpos($w, 'l.') !== 0;
        }));
        $where[] = "e.platform = 'Tiktok'";
        $where[] = "e.link_upload <> ''";
        $where[] = "e.status = 'Aktif'";

        $sql = "
            SELECT REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS cid, COUNT(*) AS n
              FROM endorse e
              JOIN endorse_campaign c ON c.id = e.id_campaign
             WHERE " . implode(' AND ', $where) . "
             GROUP BY cid
            HAVING COUNT(*) > 1 AND cid IS NOT NULL AND cid <> ''
        ";

        $rows = $this->CI->mymodel->selectWithQuery($sql) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[strval($r['cid'])] = intval($r['n']);
        }
        return $out;
    }

    /**
     * Endorses whose LATEST queue outcome is a provider-unresolvable failure.
     *
     * Joins on MAX(id) so a post that has since succeeded is not permanently
     * branded unresolved, and matches the full pattern set from
     * Endorse_sync::PROVIDER_UNRESOLVABLE_PATTERNS rather than a single string.
     */
    private function unresolved_endorse_ids(array $endorseIds): array
    {
        if (empty($endorseIds)) {
            return [];
        }
        $idList = implode(',', array_map('intval', $endorseIds));

        $patterns = array_merge(
            Endorse_sync::PROVIDER_UNRESOLVABLE_PATTERNS,
            ['stats data tidak ditemukan', 'url tidak ditemukan']
        );
        $likes = [];
        foreach ($patterns as $p) {
            $likes[] = 'latest.error_message LIKE ' . $this->CI->db->escape('%' . $p . '%');
        }

        $sql = "
            SELECT latest.id_endorse
              FROM endorse_refresh_queue latest
              INNER JOIN (
                    SELECT id_endorse, MAX(id) AS max_id
                      FROM endorse_refresh_queue
                     WHERE id_endorse IN ($idList)
                     GROUP BY id_endorse
              ) picked ON picked.max_id = latest.id
             WHERE latest.status = 'failed'
               AND (" . implode(' OR ', $likes) . ")
        ";

        $rows = $this->CI->mymodel->selectWithQuery($sql) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[intval($r['id_endorse'])] = true;
        }
        return $out;
    }

    // --------------------------------------------------------------- derivation

    /**
     * Active posts in the selected population, with the date they enter scope.
     *
     * Needed so that a post the provider has NEVER resolved still counts toward
     * unresolved_post_count. Such posts produce no rows in endorse_logs at all,
     * so they are invisible to the observation query.
     */
    private function scope_posts(array $filters): array
    {
        $where = array_values(array_filter($filters['where'], function ($w) {
            return strpos($w, 'l.') !== 0;
        }));
        $where[] = "e.status = 'Aktif'";
        $where[] = "e.link_upload <> ''";

        $sql = "
            SELECT e.id, DATE(e.posting_at) AS entered
              FROM endorse e
              JOIN endorse_campaign c ON c.id = e.id_campaign
             WHERE " . implode(' AND ', $where);

        $rows = $this->CI->mymodel->selectWithQuery($sql) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[intval($r['id'])] = strval($r['entered'] ?? '');
        }
        return $out;
    }

    /**
     * Per date, how many in-scope posts had no trustworthy observation.
     *
     * A post is in scope on date D once it was posted on or before D, or once it
     * has been observed on or before D. In-scope posts with no snapshot that day
     * are reported as unresolved — they are never rendered as zero-view posts,
     * and they never dilute observed_daily_growth.
     */
    private function unresolved_by_date(array $rows, array $scopePosts, array $dates): array
    {
        $firstSeen = [];
        $seenOnDate = [];
        foreach ($rows as $r) {
            $id = intval($r['id_endorse']);
            $d = strval($r['log_date']);
            if (!isset($firstSeen[$id]) || $d < $firstSeen[$id]) {
                $firstSeen[$id] = $d;
            }
            $seenOnDate[$d][$id] = true;
        }

        // Union of posts we ever observed and active posts in the filter scope.
        $entered = [];
        foreach ($firstSeen as $id => $d) {
            $entered[$id] = $d;
        }
        foreach ($scopePosts as $id => $postedAt) {
            $candidate = ($postedAt !== '' && $postedAt !== '0000-00-00') ? $postedAt : null;
            if (isset($entered[$id])) {
                // Earliest of posting date and first observation.
                if ($candidate !== null && $candidate < $entered[$id]) {
                    $entered[$id] = $candidate;
                }
            } elseif ($candidate !== null) {
                $entered[$id] = $candidate;
            }
        }

        $out = [];
        foreach ($dates as $d) {
            $n = 0;
            foreach ($entered as $id => $first) {
                if ($d < $first) {
                    continue; // not yet in scope on this date
                }
                if (!isset($seenOnDate[$d][$id])) {
                    $n++;
                }
            }
            $out[$d] = $n;
        }
        return $out;
    }

    /**
     * Cumulative values carried forward for dates with no fresh observation.
     *
     * Only the total-views line uses these; daily growth never does. Every
     * carried value is counted so the UI can label it as carried rather than
     * claiming an exact observation for that date.
     */
    private function carried_by_date(array $rows, array $dates): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[intval($r['id_endorse'])][strval($r['log_date'])] = intval($r['views_after']);
        }

        $out = [];
        foreach ($dates as $d) {
            $out[$d] = ['count' => 0, 'views' => 0];
        }

        foreach ($byId as $observations) {
            ksort($observations);
            $first = array_key_first($observations);
            $last = null;
            foreach ($dates as $d) {
                if ($d < $first) {
                    continue;
                }
                if (isset($observations[$d])) {
                    $last = $observations[$d];
                    continue;
                }
                if ($last !== null) {
                    $out[$d]['count']++;
                    $out[$d]['views'] += $last;
                }
            }
        }

        return $out;
    }

    // -------------------------------------------------------------- diagnostics

    /**
     * Legacy comparison figures for shadow mode: the exact aggregate the legacy
     * chart would produce for the same filters, so the two can be diffed without
     * either path changing behaviour.
     */
    public function legacy_daily_views(array $get): array
    {
        $filters = Endorse_analytics_v2::build_filters($get, [$this->CI->db, 'escape_str']);
        $where = array_merge($filters['where'], [
            'l.log_date >= ' . $this->CI->db->escape($filters['from']),
            'l.log_date <= ' . $this->CI->db->escape($filters['until']),
        ]);

        $sql = "
            SELECT l.log_date AS date,
                   SUM(GREATEST(COALESCE(l.views,0),0)) AS legacy_views
              FROM endorse_logs l
              JOIN endorse e          ON e.id = l.id_endorse
              JOIN endorse_campaign c ON c.id = e.id_campaign
             WHERE " . implode(' AND ', $where) . "
             GROUP BY l.log_date
             ORDER BY l.log_date ASC
        ";

        $rows = $this->CI->mymodel->selectWithQuery($sql) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[strval($r['date'])] = intval($r['legacy_views']);
        }
        return $out;
    }

    /** EXPLAIN output for the main observation query — Phase 12 evidence. */
    public function explain(array $get): array
    {
        $filters = Endorse_analytics_v2::build_filters($get, [$this->CI->db, 'escape_str']);
        $from = $this->CI->db->escape($filters['from']);
        $until = $this->CI->db->escape($filters['until']);
        $floor = $this->CI->db->escape(
            date('Y-m-d', strtotime($filters['from'] . ' -' . $this->lookback_days() . ' days'))
        );
        $inner = array_merge($filters['where'], [
            'l.log_date <= ' . $until,
            'l.log_date >= ' . $floor,
            'l.views_after > 0',
        ]);

        $sql = "EXPLAIN SELECT x.* FROM (
                SELECT l.id, l.id_endorse, l.log_date, l.views, l.views_after,
                       LAG(l.views_after) OVER w AS prev_after,
                       LAG(l.log_date)    OVER w AS prev_log_date
                  FROM endorse_logs l
                  JOIN endorse e          ON e.id = l.id_endorse
                  JOIN endorse_campaign c ON c.id = e.id_campaign
                 WHERE " . implode(' AND ', $inner) . "
                WINDOW w AS (PARTITION BY l.id_endorse ORDER BY l.log_date)
            ) x WHERE x.log_date BETWEEN {$from} AND {$until}";

        return $this->CI->mymodel->selectWithQuery($sql) ?: [];
    }
}
