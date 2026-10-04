<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Endorse_analytics_read_model.php';

/**
 * Read-side snapshot for the /endorse campaign detail page.
 *
 * endorse_logs stays the record. Triggers on endorse_logs / endorse mark a
 * campaign dirty in endorse_campaign_snapshot; refresh_due() (cron
 * api/cronjob/endorse-snapshot) rebuilds dirty campaigns into
 * endorse_campaign_daily, and the page reads a date slice from there.
 *
 * Only campaign-only requests (no PIC/product/status/keyword filter) are served
 * from the snapshot; anything else returns null and the caller computes live.
 */
class Endorse_campaign_snapshot
{
    /** @var CI_Controller */
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->helper('env');
    }

    /**
     * Rebuild campaigns that are dirty (debounced) or whose snapshot ends before today.
     *
     * @return array per-campaign results, for the cron log
     */
    public function refresh_due(int $budgetSeconds = 35): array
    {
        $db = $this->CI->db;
        $lock = $db->query("SELECT GET_LOCK('endorse_campaign_snapshot', 0) AS l")->row_array();
        if (intval($lock['l'] ?? 0) !== 1) {
            return [['skipped' => 'another refresh is running']];
        }

        // ponytail: whole-campaign rebuild (largest ~4-8s). Incremental per-day rebuild if this cron gets busy.
        $minInterval = max(0, intval(env('ENDORSE_SNAPSHOT_MIN_INTERVAL', 300)));
        $started = microtime(true);
        $out = [];
        try {
            $due = $db->query("
                SELECT id_campaign FROM endorse_campaign_snapshot
                 WHERE built_at IS NULL
                    OR built_until < CURDATE()
                    OR calculation_version <> " . $db->escape(Endorse_analytics_v2::CALCULATION_VERSION) . "
                    OR (dirty_at > built_at AND built_at < NOW(6) - INTERVAL {$minInterval} SECOND)
                 ORDER BY built_at IS NOT NULL, dirty_at
            ")->result_array();
            foreach ($due as $r) {
                if (microtime(true) - $started > $budgetSeconds) break;
                $out[] = $this->rebuild(intval($r['id_campaign']));
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('endorse_campaign_snapshot')");
        }
        return $out;
    }

    /** Rebuild one campaign's snapshot from endorse_logs. */
    public function rebuild(int $campaignId): array
    {
        $db = $this->CI->db;
        $t = microtime(true);
        // Writes landing while we build stay newer than built_at, so the campaign remains dirty.
        $builtAt = $db->query("SELECT NOW(6) AS t")->row_array()['t'];
        $snap = (new Endorse_analytics_read_model())->build_campaign_snapshot($campaignId, date('Y-m-d'));

        $rows = [];
        foreach (array_unique(array_merge(array_keys($snap['buckets']), array_keys($snap['legacy']))) as $d) {
            $g = $snap['legacy'][$d] ?? null;
            $rows[] = [
                'id_campaign' => $campaignId,
                'log_date' => $d,
                'v2_bucket' => isset($snap['buckets'][$d]) ? json_encode($snap['buckets'][$d]) : null,
                'v2_observasi' => intval($snap['observasi'][$d] ?? 0),
                'views' => $g['views'] ?? 0,
                'likes' => $g['likes'] ?? 0,
                'comment' => $g['comment'] ?? 0,
                'share_save' => $g['share_save'] ?? 0,
                'cost' => $g['cost'] ?? null,
                'endorse' => $g['endorse'] ?? 0,
            ];
        }

        $db->trans_start();
        $db->query("DELETE FROM endorse_campaign_daily WHERE id_campaign = {$campaignId}");
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->insert_batch('endorse_campaign_daily', $chunk);
        }
        $db->query("
            UPDATE endorse_campaign_snapshot
               SET built_at = " . $db->escape($builtAt) . ",
                   built_from = " . $db->escape($snap['from']) . ",
                   built_until = " . $db->escape($snap['until']) . ",
                   calculation_version = " . $db->escape(Endorse_analytics_v2::CALCULATION_VERSION) . ",
                   dup_groups = " . intval($snap['dup_groups']) . ",
                   dup_rows = " . intval($snap['dup_rows']) . ",
                   pre_from = " . $db->escape(json_encode($snap['pre_from'])) . ",
                   build_ms = " . intval((microtime(true) - $t) * 1000) . "
             WHERE id_campaign = {$campaignId}");
        $db->trans_complete();

        return ['id_campaign' => $campaignId, 'days' => count($rows), 'ms' => intval((microtime(true) - $t) * 1000),
            'ok' => $db->trans_status()];
    }

    /** V2 chart payload from the snapshot, or null when the request needs a live build. */
    public function v2_payload(array $get): ?array
    {
        $db = $this->CI->db;
        $filters = Endorse_analytics_v2::build_filters($get, [$db, 'escape_str']);
        $campaignId = intval($get['id_campaign'] ?? 0);
        if ($campaignId <= 0 || $filters['where'] !== ['e.id_campaign = ' . $campaignId]) return null;

        $state = $this->fresh_state($campaignId, $filters['until']);
        if ($state === null) return null;

        // MySQL's JSON type re-sorts object keys; put them back in build()'s order.
        $keyOrder = Endorse_analytics_v2::empty_buckets([''])[''];
        $byDate = [];
        foreach ($db->query("SELECT log_date, v2_bucket FROM endorse_campaign_daily
            WHERE id_campaign = {$campaignId} AND v2_bucket IS NOT NULL
              AND log_date BETWEEN " . $db->escape($filters['from']) . " AND " . $db->escape($filters['until']))->result_array() as $r) {
            $byDate[$r['log_date']] = array_replace($keyOrder, json_decode($r['v2_bucket'], true));
        }
        $obs = $db->query("SELECT COALESCE(SUM(v2_observasi), 0) AS n FROM endorse_campaign_daily
            WHERE id_campaign = {$campaignId} AND log_date <= " . $db->escape($filters['until']))->row_array();

        $payload = Endorse_analytics_read_model::payload_from_snapshot($filters, $state, $byDate, intval($obs['n']));
        $payload['meta']['sumber'] = 'snapshot';
        $payload['meta']['snapshot_dibuat'] = $state['built_at'];
        return $payload;
    }

    /**
     * Rows shaped like Ajax::get_chart_campaign's daily-delta $sql_list result
     * (likes, comment, share_save, views, cost, endorse, opt), or null if stale.
     */
    public function legacy_daily(int $campaignId, string $from, string $until): ?array
    {
        $isDate = '/^\d{4}-\d{2}-\d{2}$/';
        if ($campaignId <= 0 || !preg_match($isDate, $from) || !preg_match($isDate, $until)) return null;
        if ($this->fresh_state($campaignId, $until) === null) return null;
        $db = $this->CI->db;
        return $db->query("SELECT likes, comment, share_save, views, cost, endorse, DATE_FORMAT(log_date, '%Y-%m-%d') AS opt
            FROM endorse_campaign_daily
            WHERE id_campaign = {$campaignId} AND endorse > 0
              AND log_date BETWEEN " . $db->escape($from) . " AND " . $db->escape($until) . "
            ORDER BY log_date")->result_array();
    }

    private function fresh_state(int $campaignId, string $until): ?array
    {
        if (!$this->CI->db->table_exists('endorse_campaign_snapshot')) return null;
        $state = $this->CI->db->query("SELECT * FROM endorse_campaign_snapshot WHERE id_campaign = {$campaignId}")->row_array();
        if (empty($state['built_at']) || $state['calculation_version'] !== Endorse_analytics_v2::CALCULATION_VERSION
            || strval($state['built_until']) < $until) {
            return null;
        }
        $state['pre_from'] = json_decode(strval($state['pre_from']), true) ?: [];
        return $state;
    }
}
