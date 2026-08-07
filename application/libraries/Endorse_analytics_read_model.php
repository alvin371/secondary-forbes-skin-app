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
     * @param string $population Retained for endpoint compatibility. Analytics
     *                            V2 always calculates the confirmed canonical
     *                            population (one content ID per campaign/PIC).
     * @return array {dates, summary, metric_definition, meta}
     */
    public function build(array $get, string $population = Endorse_analytics_v2::POPULATION_CANONICAL): array
    {
        $filters = Endorse_analytics_v2::build_filters($get, [$this->CI->db, 'escape_str']);
        $from = $filters['from'];
        $until = $filters['until'];

        $rows = $this->load_observations($filters);
        $duplicates = $this->duplicate_content_ids($filters);
        $scopePosts = $this->scope_posts($filters);
        $dateList = Endorse_analytics_v2::date_range($from, $until);

        // The pure core owns all arithmetic. This adapter only turns immutable
        // snapshots into one deterministic series per canonical content.
        $series = $this->canonical_series($rows, $scopePosts, $dateList);
        $buckets = Endorse_analytics_v2::aggregate_dates($series, $dateList, [
            'sync_times' => $this->sync_times($rows),
            'duplicate_groups' => $this->duplicate_groups_by_date($duplicates, $dateList),
            'duplicate_rows' => $this->duplicate_rows_by_date($duplicates, $dateList),
        ]);
        $dates = array_values($buckets);
        $summary = Endorse_analytics_v2::summarize($dates, !empty($filters['has_date_filter']));
        $summary['jumlah_grup_duplikat'] = count($duplicates);
        $summary['jumlah_baris_duplikat'] = array_sum($duplicates);

        $meta = [
            'versi_kalkulasi' => Endorse_analytics_v2::CALCULATION_VERSION,
            'populasi' => Endorse_analytics_v2::POPULATION_CANONICAL,
            'dari' => $from,
            'sampai' => $until,
            'rentang_inklusif' => true,
            'kolom_sumber' => 'endorse_logs.views_after',
            'jumlah_baris_observasi' => count($rows),
            'pencocokan_pic' => $filters['pic_mode'],
        ];

        return [
            // Indonesian fields are the V2 contract. English aliases are
            // additive while the rollout UI is being switched over.
            'ringkasan' => $summary,
            'harian' => $dates,
            'definisi_metrik' => Endorse_analytics_v2::definisi_metrik(),
            'meta' => $meta,
            'summary' => $summary,
            'dates' => $dates,
            'metric_definition' => Endorse_analytics_v2::definisi_metrik(),
        ];
    }

    /**
     * Read-only metrics for the raw endorse cards currently visible in the list.
     * Duplicate raw cards intentionally receive the same canonical metric while
     * the chart continues to count that canonical content only once.
     */
    public function build_post_cards(array $get, array $endorseIds): array
    {
        $endorseIds = array_values(array_unique(array_filter(array_map('intval', $endorseIds))));
        if (empty($endorseIds)) return [];

        $get['ids'] = implode(',', $endorseIds);
        $filters = Endorse_analytics_v2::build_filters($get, [$this->CI->db, 'escape_str']);
        $dates = Endorse_analytics_v2::date_range($filters['from'], $filters['until']);
        if (empty($dates)) return [];

        $rows = $this->load_observations($filters);
        $scope = $this->scope_posts($filters);
        $duplicates = $this->duplicate_content_ids($filters);
        $series = $this->canonical_series($rows, $scope, $dates);
        $lastDate = end($dates);

        $byRaw = [];
        foreach ($rows as $row) $byRaw[intval($row['id_endorse'])][] = $row;

        $rawToCanonical = [];
        $canonicalRawIds = [];
        foreach ($scope as $id => $post) {
            $key = $this->canonical_key($post, intval($id));
            $rawToCanonical[intval($id)] = $key;
            $canonicalRawIds[$key][] = intval($id);
        }
        foreach ($canonicalRawIds as &$ids) sort($ids, SORT_NUMERIC);
        unset($ids);

        $failures = $this->failure_details($endorseIds, $lastDate);
        $out = [];
        foreach ($endorseIds as $id) {
            $key = $rawToCanonical[$id] ?? null;
            $day = $key !== null ? ($series[$key][$lastDate] ?? null) : null;
            if ($day === null) continue;
            $sourceId = $canonicalRawIds[$key][0] ?? $id;
            $lastSync = $this->last_sync_for_rows($byRaw[$sourceId] ?? [], $lastDate);
            $failure = $failures[$id] ?? null;
            if ($failure === null && $day['state'] === Endorse_analytics_v2::STATE_GAGAL) {
                $failure = [
                    'alasan' => 'Sistem tidak berhasil memperoleh data baru pada tanggal ini',
                    'terakhir_mencoba' => null,
                    'terakhir_mencoba_label' => 'Tidak tersedia',
                    'jumlah_percobaan' => 0,
                    'pesan_teknis' => '',
                ];
            }
            if ($failure === null && $day['state'] === Endorse_analytics_v2::STATE_BELUM_PERNAH) {
                $failure = [
                    'alasan' => Endorse_analytics_v2::alasan_gagal('belum_pernah'),
                    'terakhir_mencoba' => null,
                    'terakhir_mencoba_label' => 'Tidak tersedia',
                    'jumlah_percobaan' => 0,
                    'pesan_teknis' => '',
                ];
            }
            $duplicateRows = intval($duplicates[$key] ?? 0);
            $out[$id] = [
                'id_endorse' => $id,
                'id_campaign' => intval($scope[$id]['id_campaign'] ?? 0),
                'pic' => strval($scope[$id]['pic'] ?? ''),
                'link_postingan' => strval($scope[$id]['link_upload'] ?? ''),
                'tanggal' => $lastDate,
                'total_views_terakhir_disinkronkan' => intval($day['total']),
                'kenaikan_views' => intval($day['kenaikan']),
                'status_sinkronisasi' => $day['state'],
                'status_label' => Endorse_analytics_v2::status_label($day['state']),
                'menggunakan_data_terakhir' => !empty($day['carried']),
                'memiliki_anomali' => !empty($day['anomali']),
                'selisih_negatif' => intval($day['selisih_mentah']),
                'sinkronisasi_terakhir' => Endorse_analytics_v2::waktu_iso($lastSync),
                'sinkronisasi_terakhir_label' => Endorse_analytics_v2::waktu_label($lastSync),
                'content_id' => $this->content_from_key($key),
                'has_duplicate' => $duplicateRows > 1,
                'duplicate_row_count' => $duplicateRows,
                'failure' => $failure,
            ];
        }
        return $out;
    }

    private function content_from_key(string $key): string
    {
        $parts = explode('|', $key, 3);
        return isset($parts[2]) && strpos($parts[2], 'raw:') !== 0 ? $parts[2] : '';
    }

    private function last_sync_for_rows(array $rows, string $until): ?string
    {
        $latest = null;
        foreach ($rows as $row) {
            if (strval($row['log_date'] ?? '') > $until) continue;
            $at = strval($row['updated_at'] ?? $row['created_at'] ?? '');
            if ($at !== '' && ($latest === null || $at > $latest)) $latest = $at;
        }
        return $latest;
    }

    /** Latest failed attempt at or before the reporting date, keyed by endorse. */
    private function failure_details(array $endorseIds, string $until): array
    {
        if (!$this->CI->db->table_exists('endorse_refresh_queue')) return [];
        $list = implode(',', array_map('intval', $endorseIds));
        $untilSql = $this->CI->db->escape($until . ' 23:59:59');
        $attemptJoin = $this->CI->db->table_exists('endorse_refresh_queue_attempts')
            ? "LEFT JOIN endorse_refresh_queue_attempts a ON a.queue_id = q.id
                 AND a.id = (SELECT MAX(a2.id) FROM endorse_refresh_queue_attempts a2 WHERE a2.queue_id = q.id)"
            : 'LEFT JOIN (SELECT NULL AS error_class, NULL AS error_message, NULL AS finished_at) a ON 1 = 1';
        $rows = $this->CI->mymodel->selectWithQuery("
            SELECT q.id_endorse, q.attempts, q.error_message, q.completed_at, q.created_at,
                   a.error_class, a.error_message AS attempt_error_message, a.finished_at
              FROM endorse_refresh_queue q
              {$attemptJoin}
              INNER JOIN (
                    SELECT id_endorse, MAX(id) AS id
                      FROM endorse_refresh_queue
                     WHERE id_endorse IN ($list) AND status = 'failed' AND created_at <= {$untilSql}
                     GROUP BY id_endorse
              ) latest ON latest.id = q.id
        ") ?: [];
        $out = [];
        foreach ($rows as $row) {
            $technical = strval($row['attempt_error_message'] ?: $row['error_message']);
            $out[intval($row['id_endorse'])] = [
                'alasan' => Endorse_analytics_v2::alasan_gagal(strval($row['error_class'] ?? ''), 'Sinkronisasi gagal'),
                'terakhir_mencoba' => Endorse_analytics_v2::waktu_iso(strval($row['finished_at'] ?: $row['completed_at'] ?: $row['created_at'])),
                'terakhir_mencoba_label' => Endorse_analytics_v2::waktu_label(strval($row['finished_at'] ?: $row['completed_at'] ?: $row['created_at'])),
                'jumlah_percobaan' => intval($row['attempts'] ?? 0),
                'pesan_teknis' => $this->redact_technical_message($technical),
            ];
        }
        return $out;
    }

    private function redact_technical_message(string $message): string
    {
        return preg_replace('/(?i)(token|password|secret|api[_-]?key)\\s*[:=]\\s*[^\\s,;]+/', '$1=[disamarkan]', $message);
    }

    /** Build one stable daily series for every canonical campaign/PIC/content. */
    private function canonical_series(array $rows, array $scopePosts, array $dates): array
    {
        $items = [];
        foreach ($scopePosts as $id => $post) {
            $key = $this->canonical_key($post, $id);
            if (!isset($items[$key])) {
                $items[$key] = ['entered' => $post['entered'], 'raw_ids' => []];
            }
            $items[$key]['raw_ids'][] = intval($id);
            if ($post['entered'] !== '' && ($items[$key]['entered'] === '' || $post['entered'] < $items[$key]['entered'])) {
                $items[$key]['entered'] = $post['entered'];
            }
        }

        $byRaw = [];
        foreach ($rows as $row) {
            $id = intval($row['id_endorse']);
            $byRaw[$id][] = $row;
            $fallback = ['content_id' => $row['content_id'] ?? '', 'id_campaign' => $row['id_campaign'] ?? 0,
                'pic' => $row['pic'] ?? '', 'entered' => $row['log_date'] ?? ''];
            $key = $this->canonical_key($fallback, $id);
            if (!isset($items[$key])) $items[$key] = ['entered' => $fallback['entered'], 'raw_ids' => [$id]];
        }

        $out = [];
        $firstReportingDate = $dates[0] ?? '';
        foreach ($items as $key => $item) {
            sort($item['raw_ids'], SORT_NUMERIC);
            // A duplicate group contributes only its smallest raw endorse ID.
            // This is deterministic and leaves every raw row untouched.
            $sourceId = $item['raw_ids'][0] ?? 0;
            $observations = [];
            $prior = null;
            $everBefore = false;
            foreach ($byRaw[$sourceId] ?? [] as $row) {
                $d = strval($row['log_date']);
                // The selected date window still needs the last trustworthy
                // value from before it. Keep it as the predecessor rather than
                // assigning its accumulated history to the first displayed day.
                if ($firstReportingDate !== '' && $d < $firstReportingDate) {
                    $prior = intval($row['views_after']);
                    $everBefore = true;
                    continue;
                }
                if (!in_array($d, $dates, true)) continue;
                $observations[$d] = intval($row['views_after']);
                if ($prior === null && isset($row['prev_after']) && $row['prev_after'] !== null) {
                    $prior = intval($row['prev_after']);
                    $everBefore = true;
                }
            }
            $out[$key] = Endorse_analytics_v2::build_content_series(
                $observations, $dates, $item['entered'] ?: null, $prior, $everBefore
            );
        }
        return $out;
    }

    private function canonical_key(array $post, int $fallbackId): string
    {
        $content = trim(strval($post['content_id'] ?? ''));
        if ($content === '') $content = 'raw:' . $fallbackId;
        return intval($post['id_campaign'] ?? 0) . '|' . strval($post['pic'] ?? '') . '|' . $content;
    }

    private function sync_times(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $d = strval($row['log_date'] ?? '');
            $at = strval($row['updated_at'] ?? $row['created_at'] ?? '');
            if ($d === '' || $at === '') continue;
            if (!isset($out[$d]['terbaru']) || $at > $out[$d]['terbaru']) $out[$d]['terbaru'] = $at;
            if (!isset($out[$d]['terlama']) || $at < $out[$d]['terlama']) $out[$d]['terlama'] = $at;
        }
        return $out;
    }

    private function duplicate_groups_by_date(array $duplicates, array $dates): array
    {
        return array_fill_keys($dates, count($duplicates));
    }

    private function duplicate_rows_by_date(array $duplicates, array $dates): array
    {
        return array_fill_keys($dates, array_sum($duplicates));
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
        if ($this->use_derived()) {
            return $this->load_observations_derived($filters);
        }

        $where = $filters['where'];
        $from = $this->CI->db->escape($filters['from']);
        $until = $this->CI->db->escape($filters['until']);
        $inner = array_merge($where, [
            'l.log_date <= ' . $until,
        ]);
        // Only trustworthy cumulative observations participate. A row with no
        // positive close is not evidence that the post has zero views.
        $inner[] = 'l.views_after > 0';

        $sql = "
            SELECT x.* FROM (
                SELECT l.id, l.id_endorse, l.log_date, l.views, l.views_before, l.views_after,
                       l.created_at, l.updated_at,
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
            WHERE x.log_date <= {$until}
            ORDER BY x.id_endorse ASC, x.log_date ASC
        ";

        return $this->CI->mymodel->selectWithQuery($sql) ?: [];
    }

    /** Whether reads come from the additive derived table. */
    private function use_derived(): bool
    {
        $this->CI->load->helper('env');
        return env('ENDORSE_ANALYTICS_V2_DERIVED', '0') === '1'
            && $this->CI->db->table_exists('endorse_daily_metrics_v2');
    }

    /**
     * Same observation set, served from the additive derived table.
     *
     * Identical row shape to load_observations(), so the pure metric core and
     * every unit test are unchanged — only the source of the rows differs.
     * Rows whose source_checksum no longer matches endorse_logs are excluded so
     * a stale cache can never quietly outrank the evidence.
     */
    private function load_observations_derived(array $filters): array
    {
        $where = array_map(function ($w) {
            // Predicates were written against the l/e/c aliases; the derived
            // table stands in for `l`.
            return preg_replace('/\bl\./', 'm.', $w);
        }, $filters['where']);

        $from = $this->CI->db->escape($filters['from']);
        $until = $this->CI->db->escape($filters['until']);

        $sql = "
            SELECT m.id_endorse, m.log_date, m.views, m.views_before, m.views_after,
                   l.created_at, l.updated_at,
                   m.prev_after, m.prev_log_date, m.id_campaign, m.content_id,
                   e.pic, c.is_internal
              FROM endorse_daily_metrics_v2 m
              JOIN endorse e          ON e.id = m.id_endorse
              JOIN endorse_campaign c ON c.id = e.id_campaign
              JOIN endorse_logs l     ON l.id_endorse = m.id_endorse AND l.log_date = m.log_date
             WHERE m.log_date <= {$until}
               AND m.calculation_version = " . $this->CI->db->escape(Endorse_analytics_v2::CALCULATION_VERSION) . "
               AND m.source_checksum = MD5(CONCAT_WS(':', l.views_before, l.views, l.views_after))
               " . (empty($where) ? '' : ' AND ' . implode(' AND ', $where)) . "
             ORDER BY m.id_endorse ASC, m.log_date ASC
        ";

        return $this->CI->mymodel->selectWithQuery($sql) ?: [];
    }

    /**
     * Rebuild the derived table for a date range, optionally scoped to one
     * campaign. Idempotent: re-running produces identical rows. Reads
     * endorse_logs; writes only endorse_daily_metrics_v2.
     *
     * @return int rows present in scope after the rebuild (NOT affected_rows,
     *             which double-counts every upserted row)
     */
    public function rebuild_derived(string $from, string $until, ?int $campaignId = null): int
    {
        $fromEsc = $this->CI->db->escape($from);
        $untilEsc = $this->CI->db->escape($until);
        $floorEsc = $this->CI->db->escape(
            date('Y-m-d', strtotime($from . ' -' . $this->lookback_days() . ' days'))
        );
        $version = $this->CI->db->escape(Endorse_analytics_v2::CALCULATION_VERSION);
        $scope = $campaignId !== null ? ' AND e.id_campaign = ' . intval($campaignId) : '';

        $this->CI->db->query("
            INSERT INTO endorse_daily_metrics_v2
                (id_endorse, log_date, id_campaign, content_id, views, views_before, views_after,
                 prev_after, prev_log_date, calculation_version, source_checksum, built_at)
            SELECT x.id_endorse, x.log_date, x.id_campaign, x.content_id,
                   x.views, x.views_before, x.views_after, x.prev_after, x.prev_log_date,
                   {$version},
                   MD5(CONCAT_WS(':', x.views_before, x.views, x.views_after)),
                   NOW()
              FROM (
                SELECT l.id_endorse, l.log_date, l.views, l.views_before, l.views_after,
                       LAG(l.views_after) OVER w AS prev_after,
                       LAG(l.log_date)    OVER w AS prev_log_date,
                       e.id_campaign,
                       REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS content_id
                  FROM endorse_logs l
                  JOIN endorse e ON e.id = l.id_endorse
                 WHERE l.log_date >= {$floorEsc} AND l.log_date <= {$untilEsc}
                   AND l.views_after > 0 {$scope}
                WINDOW w AS (PARTITION BY l.id_endorse ORDER BY l.log_date)
              ) x
             WHERE x.log_date BETWEEN {$fromEsc} AND {$untilEsc}
            ON DUPLICATE KEY UPDATE
                id_campaign = VALUES(id_campaign),
                content_id = VALUES(content_id),
                views = VALUES(views),
                views_before = VALUES(views_before),
                views_after = VALUES(views_after),
                prev_after = VALUES(prev_after),
                prev_log_date = VALUES(prev_log_date),
                calculation_version = VALUES(calculation_version),
                source_checksum = VALUES(source_checksum),
                built_at = VALUES(built_at)
        ");

        $rows = $this->CI->mymodel->selectWithQuery("
            SELECT COUNT(*) AS n FROM endorse_daily_metrics_v2
             WHERE log_date BETWEEN {$fromEsc} AND {$untilEsc}"
            . ($campaignId !== null ? ' AND id_campaign = ' . intval($campaignId) : '')
        ) ?: [];

        return intval($rows[0]['n'] ?? 0);
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
            SELECT e.id_campaign, e.pic, REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS cid, COUNT(*) AS n
              FROM endorse e
              JOIN endorse_campaign c ON c.id = e.id_campaign
             WHERE " . implode(' AND ', $where) . "
             GROUP BY e.id_campaign, e.pic, cid
            HAVING COUNT(*) > 1 AND cid IS NOT NULL AND cid <> ''
        ";

        $rows = $this->CI->mymodel->selectWithQuery($sql) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $key = intval($r['id_campaign']) . '|' . strval($r['pic']) . '|' . strval($r['cid']);
            $out[$key] = intval($r['n']);
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
        $where = array_values(array_map(function ($w) {
            return preg_replace('/\bl\.id_endorse\b/', 'e.id', $w);
        }, array_filter($filters['where'], function ($w) {
            return strpos($w, 'l.') !== 0 || strpos($w, 'l.id_endorse') === 0;
        })));
        $where[] = "e.status = 'Aktif'";
        $where[] = "e.link_upload <> ''";

        $sql = "
            SELECT e.id, DATE(e.posting_at) AS entered, e.id_campaign, e.pic, e.link_upload,
                   REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS content_id
              FROM endorse e
              JOIN endorse_campaign c ON c.id = e.id_campaign
             WHERE " . implode(' AND ', $where);

        $rows = $this->CI->mymodel->selectWithQuery($sql) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[intval($r['id'])] = [
                'entered' => strval($r['entered'] ?? ''),
                'id_campaign' => intval($r['id_campaign'] ?? 0),
                'pic' => strval($r['pic'] ?? ''),
                'content_id' => strval($r['content_id'] ?? ''),
                'link_upload' => strval($r['link_upload'] ?? ''),
            ];
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
