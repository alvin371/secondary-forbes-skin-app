<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Endorse_repair_plan.php';

/**
 * CLI-only historical repair for endorse daily deltas.
 *
 *   php index.php endorse_analytics_repair preview  --from=2026-07-27 --until=2026-08-05
 *   php index.php endorse_analytics_repair apply    --from=... --until=... --confirmation=<token>
 *   php index.php endorse_analytics_repair rollback --run-id=<id> --confirmation=<token>
 *
 * Repairs `views_before` and `views` only. `views_after` is the observed
 * cumulative snapshot and is never written. Rows dated 2026-08-06 or later are
 * unreachable by construction (Endorse_repair_plan::WINDOW_MAX).
 */
class Endorse_analytics_repair extends CI_Controller
{
    const CHUNK = 200;

    private array $opts = [];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Endorse analytics repair is CLI-only.', 403);
        }
        date_default_timezone_set('Asia/Jakarta');
        $this->load->database();
        $this->load->model('mymodel');
        $this->opts = $this->parse_options();
    }

    // ------------------------------------------------------------------ preview

    public function preview()
    {
        $rows = $this->load_rows();
        $plans = $this->build_plans($rows);
        $this->render_report($plans, 'PREVIEW (read-only — no data written)');

        $safe = array_values(array_filter($plans, fn($p) => $p['category'] === Endorse_repair_plan::CAT_SAFE));
        $this->out('');
        $this->out('preview_checksum : ' . Endorse_repair_plan::checksum($safe));
        $this->out('safe_rows        : ' . count($safe));
        $this->out('confirmation     : ' . $this->confirmation_token($safe));
        $this->write_output_artifact($plans);
    }

    // -------------------------------------------------------------------- apply

    public function apply()
    {
        $rows = $this->load_rows();
        $plans = $this->build_plans($rows);
        $safe = array_values(array_filter($plans, fn($p) => $p['category'] === Endorse_repair_plan::CAT_SAFE));

        if (empty($safe)) {
            $this->out('Nothing to repair for this selection.');
            return;
        }

        $expected = $this->confirmation_token($safe);
        if (strval($this->opts['confirmation'] ?? '') !== $expected) {
            $this->out('ABORT: confirmation token mismatch.');
            $this->out('Run preview first, then pass --confirmation=' . $expected);
            return;
        }

        $runId = 'repair_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        $checksum = Endorse_repair_plan::checksum($safe);
        $sha = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: '');
        $operator = strval($this->opts['operator'] ?? get_current_user());
        $now = date('Y-m-d H:i:s');

        $applied = 0; $skipped = 0;

        foreach (array_chunk($safe, self::CHUNK) as $chunk) {
            $this->db->trans_start();
            foreach ($chunk as $p) {
                // Optimistic guard: only write when the row is byte-identical to
                // what preview saw. Anything touched since is skipped and reported.
                $this->db->query("
                    UPDATE endorse_logs
                       SET views_before = ?, views = ?
                     WHERE id = ?
                       AND views_before = ? AND views = ? AND views_after = ?
                ", [
                    $p['proposed_views_before'], $p['proposed_views'], $p['id'],
                    $p['stored_views_before'], $p['stored_views'], $p['views_after'],
                ]);

                if ($this->db->affected_rows() !== 1) {
                    $skipped++;
                    continue;
                }

                $this->db->insert('endorse_logs_repair_audit', [
                    'run_id' => $runId, 'action' => 'apply', 'operator' => $operator,
                    'log_id' => $p['id'], 'id_endorse' => $p['id_endorse'],
                    'id_campaign' => $p['id_campaign'], 'log_date' => $p['log_date'],
                    'old_views_before' => $p['stored_views_before'], 'old_views' => $p['stored_views'],
                    'new_views_before' => $p['proposed_views_before'], 'new_views' => $p['proposed_views'],
                    'views_after' => $p['views_after'],
                    'preview_checksum' => $checksum, 'apply_checksum' => $checksum,
                    'reason' => $p['reason'], 'source_sha' => $sha, 'created_at' => $now,
                ]);
                $applied++;
            }
            $this->db->trans_complete();
            if ($this->db->trans_status() === false) {
                $this->out('ABORT: transaction failed; run ' . $runId . ' stopped after ' . $applied . ' rows.');
                return;
            }
        }

        $this->out('run_id   : ' . $runId);
        $this->out('applied  : ' . $applied);
        $this->out('skipped  : ' . $skipped . ' (changed since preview)');
        $this->out('checksum : ' . $checksum);
        $this->out('rollback : php index.php endorse_analytics_repair rollback --run-id=' . $runId
            . ' --confirmation=' . substr(md5($runId), 0, 12));
    }

    // ----------------------------------------------------------------- rollback

    public function rollback()
    {
        $runId = strval($this->opts['run-id'] ?? '');
        if ($runId === '') { $this->out('ABORT: --run-id is required.'); return; }
        if (strval($this->opts['confirmation'] ?? '') !== substr(md5($runId), 0, 12)) {
            $this->out('ABORT: confirmation token mismatch for run ' . $runId); return;
        }

        $rows = $this->mymodel->selectWithQuery("
            SELECT * FROM endorse_logs_repair_audit
             WHERE run_id = " . $this->db->escape($runId) . " AND action = 'apply'
             ORDER BY id ASC
        ") ?: [];

        if (empty($rows)) { $this->out('No applied rows found for run ' . $runId); return; }

        $restored = 0; $refused = 0;
        $now = date('Y-m-d H:i:s');
        $operator = strval($this->opts['operator'] ?? get_current_user());

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->db->trans_start();
            foreach ($chunk as $a) {
                // Refuse to clobber anything changed after this run wrote it.
                $this->db->query("
                    UPDATE endorse_logs
                       SET views_before = ?, views = ?
                     WHERE id = ?
                       AND views_before = ? AND views = ? AND views_after = ?
                ", [
                    $a['old_views_before'], $a['old_views'], $a['log_id'],
                    $a['new_views_before'], $a['new_views'], $a['views_after'],
                ]);

                if ($this->db->affected_rows() !== 1) { $refused++; continue; }

                $this->db->insert('endorse_logs_repair_audit', [
                    'run_id' => $runId, 'action' => 'rollback', 'operator' => $operator,
                    'log_id' => $a['log_id'], 'id_endorse' => $a['id_endorse'],
                    'id_campaign' => $a['id_campaign'], 'log_date' => $a['log_date'],
                    'old_views_before' => $a['new_views_before'], 'old_views' => $a['new_views'],
                    'new_views_before' => $a['old_views_before'], 'new_views' => $a['old_views'],
                    'views_after' => $a['views_after'], 'reason' => 'rollback',
                    'source_sha' => $a['source_sha'], 'created_at' => $now,
                ]);
                $restored++;
            }
            $this->db->trans_complete();
        }

        $this->out('rolled_back : ' . $restored);
        $this->out('refused     : ' . $refused . ' (changed after this run)');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Load candidate rows with their authoritative predecessor.
     *
     * The inner select intentionally reaches BEFORE from_date so LAG() never
     * defaults a predecessor to zero; the window filter is applied outside.
     */
    private function load_rows(): array
    {
        $from  = $this->date_opt('from', Endorse_repair_plan::WINDOW_MIN);
        $until = $this->date_opt('until', Endorse_repair_plan::WINDOW_MAX);

        // Hard clamp — the command can never reach forward-fixed data.
        if ($from < Endorse_repair_plan::WINDOW_MIN) $from = Endorse_repair_plan::WINDOW_MIN;
        if ($until > Endorse_repair_plan::WINDOW_MAX) $until = Endorse_repair_plan::WINDOW_MAX;

        $where = ["x.log_date BETWEEN " . $this->db->escape($from) . " AND " . $this->db->escape($until)];
        if (!empty($this->opts['campaign']))  $where[] = 'x.endorse_campaign = ' . intval($this->opts['campaign']);
        if (!empty($this->opts['endorse']))   $where[] = 'x.id_endorse = ' . intval($this->opts['endorse']);
        if (!empty($this->opts['pic']))       $where[] = 'x.pic LIKE ' . $this->db->escape('%' . $this->opts['pic'] . '%');
        if (!empty($this->opts['content-id']))$where[] = 'x.content_id = ' . $this->db->escape(strval($this->opts['content-id']));
        if (isset($this->opts['internal']))   $where[] = 'x.is_internal = 1';
        if (isset($this->opts['external']))   $where[] = 'x.is_internal = 0';
        $limit = !empty($this->opts['limit']) ? ' LIMIT ' . intval($this->opts['limit']) : '';

        return $this->mymodel->selectWithQuery("
            SELECT x.* FROM (
                SELECT l.id, l.id_endorse, l.id_campaign, l.log_date,
                       l.views_before, l.views, l.views_after,
                       LAG(l.views_after) OVER (PARTITION BY l.id_endorse ORDER BY l.log_date) AS prev_after,
                       e.id_campaign AS endorse_campaign, e.pic, e.status AS endorse_status,
                       c.is_internal,
                       REGEXP_SUBSTR(e.link_upload,'[0-9]{15,}') AS content_id,
                       CASE WHEN e.link_upload LIKE '%/photo/%' THEN 'photo' ELSE 'video' END AS media
                  FROM endorse_logs l
                  JOIN endorse e          ON e.id = l.id_endorse
                  JOIN endorse_campaign c ON c.id = e.id_campaign
                 WHERE l.log_date <= " . $this->db->escape($until) . "
            ) x
            WHERE " . implode(' AND ', $where) . "
            ORDER BY x.id_endorse ASC, x.log_date ASC" . $limit
        ) ?: [];
    }

    /** Content IDs that appear on more than one active endorse row. */
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

    /** Endorses whose latest queue outcome is a provider-unresolvable failure. */
    private function unresolved_endorse_ids(): array
    {
        $rows = $this->mymodel->selectWithQuery("
            SELECT DISTINCT id_endorse FROM endorse_refresh_queue
             WHERE status = 'failed' AND error_message LIKE '%Url parsing is failed%'
        ") ?: [];
        return array_flip(array_column($rows, 'id_endorse'));
    }

    private function build_plans(array $rows): array
    {
        $dupes = $this->duplicate_content_ids();
        $unresolved = $this->unresolved_endorse_ids();
        $plans = [];

        foreach ($rows as $r) {
            $r['is_duplicate'] = isset($dupes[strval($r['content_id'])]);
            $r['is_unresolved'] = isset($unresolved[intval($r['id_endorse'])]);
            $plan = Endorse_repair_plan::classify_row($r);

            $plans[] = $plan + [
                'id' => intval($r['id']),
                'id_endorse' => intval($r['id_endorse']),
                'id_campaign' => intval($r['id_campaign']),
                'log_date' => strval($r['log_date']),
                'pic' => strval($r['pic']),
                'media' => strval($r['media']),
                'is_internal' => intval($r['is_internal']),
                'content_id' => strval($r['content_id']),
                'stored_views_before' => intval($r['views_before']),
                'stored_views' => intval($r['views']),
                'views_after' => intval($r['views_after']),
            ];
        }
        return $plans;
    }

    private function render_report(array $plans, string $title): void
    {
        $this->out(str_repeat('=', 78));
        $this->out($title);
        $this->out(str_repeat('=', 78));

        $byDate = [];
        $byCat = [];
        foreach ($plans as $p) {
            $d = $p['log_date']; $c = $p['category'];
            $byDate[$d][$c] = ($byDate[$d][$c] ?? 0) + 1;
            $byDate[$d]['_stored'] = ($byDate[$d]['_stored'] ?? 0) + $p['stored_views'];
            $byDate[$d]['_proposed'] = ($byDate[$d]['_proposed'] ?? 0)
                + ($c === Endorse_repair_plan::CAT_SAFE ? $p['proposed_views'] : $p['stored_views']);
            $byCat[$c] = ($byCat[$c] ?? 0) + 1;
        }
        ksort($byDate);

        $this->out(sprintf('%-12s %8s %8s %8s %8s %8s %8s %8s | %12s %12s %12s',
            'DATE','rows','correct','safe','opening','negative','dup','unres','stored','proposed','diff'));
        foreach ($byDate as $d => $v) {
            $rows = array_sum(array_intersect_key($v, array_flip([
                Endorse_repair_plan::CAT_SAFE, Endorse_repair_plan::CAT_ALREADY_CORRECT,
                Endorse_repair_plan::CAT_OPENING_DECISION, Endorse_repair_plan::CAT_NEGATIVE,
                Endorse_repair_plan::CAT_DUPLICATE_SENSITIVE, Endorse_repair_plan::CAT_UNRESOLVED,
                Endorse_repair_plan::CAT_OWNERSHIP,
            ])));
            $this->out(sprintf('%-12s %8d %8d %8d %8d %8d %8d %8d | %12s %12s %12s',
                $d, $rows,
                $v[Endorse_repair_plan::CAT_ALREADY_CORRECT] ?? 0,
                $v[Endorse_repair_plan::CAT_SAFE] ?? 0,
                $v[Endorse_repair_plan::CAT_OPENING_DECISION] ?? 0,
                $v[Endorse_repair_plan::CAT_NEGATIVE] ?? 0,
                $v[Endorse_repair_plan::CAT_DUPLICATE_SENSITIVE] ?? 0,
                $v[Endorse_repair_plan::CAT_UNRESOLVED] ?? 0,
                number_format($v['_stored']), number_format($v['_proposed']),
                number_format($v['_proposed'] - $v['_stored'])));
        }

        $this->out('');
        $this->out('CATEGORY TOTALS');
        foreach ($byCat as $c => $n) $this->out(sprintf('  %-22s %8d', $c, $n));

        $safeDiff = 0; $largest = 0; $largestRow = '';
        foreach ($plans as $p) {
            if ($p['category'] !== Endorse_repair_plan::CAT_SAFE) continue;
            $safeDiff += $p['delta_change'];
            if (abs($p['delta_change']) > abs($largest)) {
                $largest = $p['delta_change'];
                $largestRow = "log#{$p['id']} endorse {$p['id_endorse']} {$p['log_date']}";
            }
        }
        $this->out('');
        $this->out('net view change from safe repairs : ' . number_format($safeDiff));
        $this->out('largest single adjustment         : ' . number_format($largest) . '  (' . $largestRow . ')');
    }

    private function write_output_artifact(array $plans): void
    {
        $path = strval($this->opts['output'] ?? '');
        if ($path === '') return;
        file_put_contents($path, json_encode($plans, JSON_PRETTY_PRINT));
        $this->out('artifact written : ' . $path);
    }

    private function confirmation_token(array $safe): string
    {
        return substr(Endorse_repair_plan::checksum($safe), 0, 12);
    }

    private function date_opt(string $key, string $default): string
    {
        $v = strval($this->opts[$key] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
    }

    /**
     * Accepts `--key=value` and `--key:value`.
     *
     * CodeIgniter filters CLI argv through `permitted_uri_chars` (`a-z 0-9~%.:_\-`),
     * which rejects `=`. So when invoking through index.php use the colon form:
     *
     *   php index.php endorse_analytics_repair preview --from:2026-07-27 --until:2026-08-05
     *
     * The `=` form is still supported for callers that pass options out of band:
     *
     *   ENDORSE_REPAIR_ARGS="--from=2026-07-27 --until=2026-08-05" php index.php ...
     *
     * Changing permitted_uri_chars was rejected deliberately: it also governs
     * production web URL filtering.
     */
    private function parse_options(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $env = trim(strval(getenv('ENDORSE_REPAIR_ARGS') ?: ''));
        if ($env !== '') {
            $argv = array_merge($argv, preg_split('/\s+/', $env) ?: []);
        }

        $opts = [];
        foreach ($argv as $arg) {
            if (strpos($arg, '--') !== 0) continue;
            $arg = substr($arg, 2);
            if (preg_match('/^([a-z0-9_-]+)[=:](.*)$/i', $arg, $m)) {
                $opts[$m[1]] = $m[2];
            } else {
                $opts[$arg] = true;
            }
        }
        return $opts;
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
