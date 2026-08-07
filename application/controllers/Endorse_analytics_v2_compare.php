<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Endorse_analytics_read_model.php';
require_once APPPATH . 'libraries/Endorse_repair_plan.php';

/**
 * CLI-only, READ-ONLY validation harness for the V2 analytics read model.
 *
 *   php index.php endorse_analytics_v2_compare run     --campaign:27 --pic:Fazra --from:2026-08-01 --until:2026-08-05
 *   php index.php endorse_analytics_v2_compare oracle  --campaign:27 --from:2026-07-27 --until:2026-08-05
 *   php index.php endorse_analytics_v2_compare explain --campaign:27
 *   php index.php endorse_analytics_v2_compare fingerprint --from:2026-07-27 --until:2026-08-05
 *
 * There is deliberately no apply verb. Nothing here writes, and `fingerprint`
 * exists purely to prove endorse_logs is byte-identical before and after.
 */
class Endorse_analytics_v2_compare extends CI_Controller
{
    private array $opts = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Endorse analytics V2 compare is CLI-only.', 403);
        }
        date_default_timezone_set('Asia/Jakarta');
        $this->load->database();
        $this->load->model('mymodel');
        $this->load->helper('env');
        $this->opts = $this->parse_options();
    }

    // ---------------------------------------------------------------- verbs

    /** Legacy vs V2 comparison table for one filter selection. */
    public function run()
    {
        $get = $this->request_from_opts();
        $population = Endorse_analytics_v2::POPULATION_CANONICAL;

        $model = new Endorse_analytics_read_model();

        $t0 = microtime(true);
        $payload = $model->build($get, $population);
        $buildMs = (microtime(true) - $t0) * 1000;

        $t1 = microtime(true);
        $legacy = $model->legacy_daily_views($get);
        $legacyMs = (microtime(true) - $t1) * 1000;

        $this->out(str_repeat('=', 118));
        $this->out('V2 SHADOW COMPARISON (read-only) — ' . $this->scope_label());
        $this->out('population=' . $population . '  calculation_version=' . Endorse_analytics_v2::CALCULATION_VERSION);
        $this->out(str_repeat('=', 118));

        $this->out(sprintf(
            '%-12s %12s %14s %14s %14s %12s %8s %8s %-12s',
            'DATE', 'LEGACY', 'V2 GROWTH', 'V2 TOTAL', 'OPENING', 'DIFF', 'POSTS', 'UNRES', 'COMPLETE'
        ));

        foreach ($payload['harian'] as $d) {
            $legacyValue = intval($legacy[$d['tanggal']] ?? 0);
            $growth = intval($d['kenaikan_views']);
            $this->out(sprintf(
                '%-12s %12s %14s %14s %14s %12s %8d %8d %-12s',
                $d['tanggal'],
                number_format($legacyValue),
                number_format($growth),
                number_format(intval($d['total_views_terakhir_disinkronkan'])),
                number_format(intval($d['opening_views'])),
                number_format($growth - $legacyValue),
                intval($d['jumlah_post']),
                intval($d['jumlah_belum_pernah_berhasil']),
                strval($d['kelengkapan_data'])
            ));
        }

        $s = $payload['summary'];
        $this->out('');
        $this->out('SUMMARY');
        foreach ([
            'total_views_terakhir_disinkronkan', 'total_kenaikan_views', 'total_opening_views',
            'jumlah_post', 'jumlah_berhasil', 'jumlah_gagal', 'jumlah_belum_pernah_berhasil',
            'jumlah_grup_duplikat', 'jumlah_anomali', 'jumlah_menggunakan_data_terakhir',
            'kelengkapan_data',
        ] as $k) {
            $v = $s[$k] ?? '';
            $this->out(sprintf('  %-34s %s', $k, is_int($v) ? number_format($v) : strval($v)));
        }

        $this->out('');
        $this->out(sprintf('  v2_build_ms %.1f   legacy_query_ms %.1f   observation_rows %d',
            $buildMs, $legacyMs, intval($payload['meta']['jumlah_baris_observasi'])));
        $this->out('  NOTE: Kenaikan V2 dihitung per konten canonical dan tidak memasukkan opening views.');
    }

    /**
     * Independent oracle: for every row the repair planner calls safe, the V2
     * growth must equal its proposed value. Reads only; never applies.
     */
    public function oracle()
    {
        $from = strval($this->opts['from'] ?? Endorse_repair_plan::WINDOW_MIN);
        $until = strval($this->opts['until'] ?? Endorse_repair_plan::WINDOW_MAX);

        $where = ['x.log_date BETWEEN ' . $this->db->escape($from) . ' AND ' . $this->db->escape($until)];
        if (!empty($this->opts['campaign'])) $where[] = 'x.endorse_campaign = ' . intval($this->opts['campaign']);
        if (!empty($this->opts['pic']))      $where[] = 'x.pic LIKE ' . $this->db->escape('%' . $this->opts['pic'] . '%');

        $rows = $this->mymodel->selectWithQuery("
            SELECT x.* FROM (
                SELECT l.id, l.id_endorse, l.id_campaign, l.log_date,
                       l.views_before, l.views, l.views_after,
                       LAG(l.views_after) OVER w AS prev_after,
                       LAG(l.log_date)    OVER w AS prev_log_date,
                       e.id_campaign AS endorse_campaign, e.pic,
                       REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS content_id
                  FROM endorse_logs l
                  JOIN endorse e ON e.id = l.id_endorse
                 WHERE l.log_date <= " . $this->db->escape($until) . "
                WINDOW w AS (PARTITION BY l.id_endorse ORDER BY l.log_date)
            ) x WHERE " . implode(' AND ', $where) . "
            ORDER BY x.id_endorse, x.log_date
        ") ?: [];

        $dupes = $this->duplicate_content_ids();
        $checked = 0; $mismatch = 0;

        foreach ($rows as $r) {
            $r['is_duplicate'] = isset($dupes[strval($r['content_id'])]);
            $r['is_unresolved'] = false;

            $plan = Endorse_repair_plan::classify_row($r);
            if ($plan['category'] !== Endorse_repair_plan::CAT_SAFE) {
                continue;
            }
            $checked++;
            $obs = Endorse_analytics_v2::classify_observation($r);
            if ($obs['growth'] !== $plan['proposed_views']) {
                $mismatch++;
                if ($mismatch <= 20) {
                    $this->out(sprintf(
                        'MISMATCH id=%d endorse=%d date=%s planner=%d v2=%d',
                        intval($r['id']), intval($r['id_endorse']), $r['log_date'],
                        $plan['proposed_views'], $obs['growth']
                    ));
                }
            }
        }

        $this->out(str_repeat('=', 78));
        $this->out('REPAIR-PLANNER ORACLE (read-only; no repair applied)');
        $this->out('  safe rows checked : ' . number_format($checked));
        $this->out('  mismatches        : ' . number_format($mismatch));
        $this->out($mismatch === 0 ? '  RESULT: PASS — V2 matches the oracle exactly on every safe row.'
                                   : '  RESULT: FAIL');
    }

    /**
     * Rebuild the additive derived cache. Writes only to
     * endorse_daily_metrics_v2; endorse_logs is read and never modified.
     * Idempotent, and scopeable by campaign so a rebuild stays bounded.
     */
    public function rebuild()
    {
        $from = strval($this->opts['from'] ?? date('Y-m-d', strtotime('-60 days')));
        $until = strval($this->opts['until'] ?? date('Y-m-d'));
        $campaign = isset($this->opts['campaign']) ? intval($this->opts['campaign']) : null;

        $model = new Endorse_analytics_read_model();

        $t0 = microtime(true);
        $written = $model->rebuild_derived($from, $until, $campaign);
        $ms = (microtime(true) - $t0) * 1000;

        $this->out('REBUILD endorse_daily_metrics_v2 (endorse_logs untouched)');
        $this->out('  range    : ' . $from . ' .. ' . $until);
        $this->out('  campaign : ' . ($campaign === null ? 'all' : $campaign));
        $this->out('  version  : ' . Endorse_analytics_v2::CALCULATION_VERSION);
        $this->out('  rows     : ' . number_format($written));
        $this->out(sprintf('  elapsed  : %.1f ms', $ms));
    }

    /** EXPLAIN + timing for the V2 observation query — Phase 12 evidence. */
    public function explain()
    {
        $get = $this->request_from_opts();
        $model = new Endorse_analytics_read_model();

        foreach ($model->explain($get) as $row) {
            $this->out(str_repeat('-', 78));
            foreach ($row as $k => $v) {
                $this->out(sprintf('  %-16s %s', $k, strval($v)));
            }
        }

        $t0 = microtime(true);
        $payload = $model->build($get, Endorse_analytics_v2::POPULATION_RAW);
        $this->out(str_repeat('-', 78));
        $this->out(sprintf('  build_ms %.1f   observation_rows %d   dates %d',
            (microtime(true) - $t0) * 1000,
            intval($payload['meta']['observation_row_count']),
            count($payload['dates'])));
    }

    /**
     * Checksum of the historical rows, so it can be shown that reading through
     * the V2 path leaves endorse_logs byte-identical.
     */
    public function fingerprint()
    {
        $from = $this->db->escape(strval($this->opts['from'] ?? '2026-07-27'));
        $until = $this->db->escape(strval($this->opts['until'] ?? '2026-08-05'));

        $rows = $this->mymodel->selectWithQuery("
            SELECT COUNT(*) AS rows_total,
                   COALESCE(SUM(views),0) AS sum_views,
                   COALESCE(SUM(views_before),0) AS sum_views_before,
                   COALESCE(SUM(views_after),0) AS sum_views_after,
                   MD5(GROUP_CONCAT(CONCAT_WS(':', id, views_before, views, views_after)
                       ORDER BY id SEPARATOR '|')) AS fingerprint
              FROM endorse_logs
             WHERE log_date BETWEEN {$from} AND {$until}
        ") ?: [];

        foreach ($rows[0] ?? [] as $k => $v) {
            $this->out(sprintf('  %-18s %s', $k, strval($v)));
        }
        $this->out('  (group_concat_max_len may truncate the hash; the sums are the durable check)');
    }

    // -------------------------------------------------------------- helpers

    private function duplicate_content_ids(): array
    {
        $rows = $this->mymodel->selectWithQuery("
            SELECT REGEXP_SUBSTR(link_upload,'[0-9]{15,}') AS cid
              FROM endorse
             WHERE platform = 'Tiktok' AND link_upload <> '' AND status = 'Aktif'
             GROUP BY cid HAVING COUNT(*) > 1
        ") ?: [];
        return array_flip(array_column($rows, 'cid'));
    }

    /** Map CLI options onto the same request shape the endpoint consumes. */
    private function request_from_opts(): array
    {
        $get = [];
        if (!empty($this->opts['campaign'])) $get['id_campaign'] = intval($this->opts['campaign']);
        if (!empty($this->opts['campaigns'])) $get['ids_campaign'] = strval($this->opts['campaigns']);
        if (!empty($this->opts['from']))     $get['start_date'] = strval($this->opts['from']);
        if (!empty($this->opts['until']))    $get['until_date'] = strval($this->opts['until']);
        if (!empty($this->opts['brand']))    $get['brand'] = strval($this->opts['brand']);
        if (isset($this->opts['internal']))  $get['endorse_category'] = 'internal';
        if (isset($this->opts['external']))  $get['endorse_category'] = 'external';

        // --pic is a substring search, matching the dashboard keyword box.
        // --pic-exact reproduces the exact `pic[]` multi-select instead.
        if (!empty($this->opts['pic'])) {
            $get['keyword_category'] = 'PIC';
            $get['keyword'] = strval($this->opts['pic']);
        }
        if (!empty($this->opts['pic-exact'])) {
            $get['pic'] = explode(',', strval($this->opts['pic-exact']));
        }
        return $get;
    }

    private function scope_label(): string
    {
        $bits = [];
        foreach (['campaign', 'campaigns', 'pic', 'pic-exact', 'brand', 'from', 'until'] as $k) {
            if (!empty($this->opts[$k])) $bits[] = $k . '=' . $this->opts[$k];
        }
        if (isset($this->opts['internal'])) $bits[] = 'internal';
        if (isset($this->opts['external'])) $bits[] = 'external';
        return empty($bits) ? 'all campaigns' : implode(' ', $bits);
    }

    /**
     * Accepts --key=value and --key:value. The colon form exists because CI's
     * permitted_uri_chars rejects '=' in CLI segments.
     */
    private function parse_options(): array
    {
        $opts = [];
        $argv = is_array($this->input->server('argv')) ? $this->input->server('argv') : [];
        foreach (array_slice($argv, 3) as $arg) {
            if (strpos($arg, '--') !== 0) {
                continue;
            }
            $body = substr($arg, 2);
            $pos = strcspn($body, '=:');
            if ($pos === strlen($body)) {
                $opts[$body] = true;
            } else {
                $opts[substr($body, 0, $pos)] = substr($body, $pos + 1);
            }
        }
        return $opts;
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
