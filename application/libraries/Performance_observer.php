<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Request/command-level performance summary.
 *
 * This deliberately observes CodeIgniter's existing query timing arrays rather
 * than logging SQL bindings. Fingerprints are normalized and hashed before an
 * event leaves the process. It is best-effort: observability failures never
 * alter an application response.
 */
class Performance_observer
{
    private static $startedAt = null;
    private static $context = array();
    private static $workload = array();
    private static $started = false;
    private static $shutdownRegistered = false;
    private static $earlyStartedAt = null;
    private static $earlyTrace = null;
    private static $envHelperLoaded = false;

    /**
     * Runs from CodeIgniter's pre_system hook, before a controller exists.
     * It records only a wall-clock start and a validated incoming trace. Query
     * inspection still begins at post_controller_constructor, when CI's DB
     * object is available.
     */
    public static function begin_early()
    {
        if (!self::enabled() || self::$earlyStartedAt !== null || self::$started) {
            return;
        }
        self::$earlyStartedAt = microtime(true);
        $incoming = self::incomingTrace();
        self::$earlyTrace = self::validTrace($incoming) ? $incoming : null;
    }

    public static function begin()
    {
        if (self::$started || !self::enabled()) {
            return;
        }

        self::$started = true;
        self::$startedAt = self::$earlyStartedAt !== null ? self::$earlyStartedAt : microtime(true);
        self::$workload = array();
        // Several cron/API controllers terminate with die() after emitting JSON.
        // A shutdown callback makes those paths observable while finish() remains
        // idempotent for normal CodeIgniter post_system execution.
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(function () {
                Performance_observer::finish(true);
            });
        }
        $isCli = is_cli();
        $ci = get_instance();
        $router = isset($ci->router) ? $ci->router : null;
        $route = $isCli
            ? 'cli:' . self::safeToken($router ? $router->class : 'unknown') . '/' . self::safeToken($router ? $router->method : 'unknown')
            : '/' . self::safeToken($router ? $router->class : 'unknown') . '/' . self::safeToken($router ? $router->method : 'unknown');
        $incomingTrace = $isCli ? '' : self::incomingTrace();
        if ($incomingTrace === '' && self::$earlyTrace !== null) {
            $incomingTrace = self::$earlyTrace;
        }
        $trace = self::validTrace($incomingTrace) ? $incomingTrace : self::newTrace();

        self::$context = array(
            'schema_version' => self::setting('OBSERVABILITY_LOG_SCHEMA_VERSION', '1'),
            'timestamp_utc' => gmdate('c'),
            'type' => 'request_performance',
            'application' => self::setting('OBSERVABILITY_APPLICATION', 'secondary-forbes'),
            'environment' => self::setting('OBSERVABILITY_ENVIRONMENT', defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown'),
            'container_or_instance' => self::safeToken((string) (gethostname() ?: 'unknown')),
            'trace_id' => $trace,
            'parent_trace_id' => self::validTrace($incomingTrace) ? $incomingTrace : null,
            'request_id' => self::newTrace(),
            'execution_id' => self::newTrace(),
            'execution_kind' => $isCli ? 'cli' : (strpos($route, '/api_v2/cronjob_') === 0 ? 'cron_http' : 'http'),
            'route_or_command' => $route,
            'normalized_route' => $route,
            'method' => $isCli ? 'CLI' : self::safeToken((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'http_method' => $isCli ? null : self::safeToken((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        );
    }

    /** Allows a worker to add bounded business counters without logging items. */
    public static function set_workload(array $workload)
    {
        $allowed = array('job_or_command_name', 'queue_name', 'batch_id', 'safe_business_context', 'attempt_number', 'worker_identity', 'concurrency', 'items_requested', 'items_processed', 'items_succeeded', 'items_failed', 'retry_count', 'transaction_count', 'external_api_total_ms');
        foreach ($allowed as $key) {
            if (array_key_exists($key, $workload) && (is_scalar($workload[$key]) || $workload[$key] === null)) {
                self::$workload[$key] = $workload[$key];
            }
        }
    }

    /** Set parent/child IDs when a queued execution is resumed asynchronously. */
    public static function set_trace_context($parentTraceId, $executionTraceId = null)
    {
        if (self::validTrace($parentTraceId)) {
            self::$context['parent_trace_id'] = $parentTraceId;
        }
        if (self::validTrace($executionTraceId)) {
            self::$context['trace_id'] = $executionTraceId;
        }
    }

    public static function finish($fromShutdown = false)
    {
        if (!self::$started) {
            return;
        }
        // Prevent duplicate events when both post_system and the shutdown
        // callback run on the same request.
        self::$started = false;
        try {
            $ci = get_instance();
            $db = isset($ci->db) ? $ci->db : null;
            $summary = self::querySummary($db);
            $duration = max(0, (microtime(true) - self::$startedAt) * 1000);
            $status = is_cli() ? null : (function_exists('http_response_code') ? http_response_code() : 200);
            $lastError = error_get_last();
            $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            $fatal = is_array($lastError) && isset($lastError['type']) && (($lastError['type'] & $fatalTypes) !== 0);
            $executionStatus = $fatal || ($status !== null && $status >= 500)
                ? 'failed'
                : ($fromShutdown ? 'unknown' : 'completed');
            $event = array_merge(self::$context, self::$workload, array(
                'status_code' => $status,
                'execution_status' => $executionStatus,
                'shutdown_reason' => $fatal ? 'fatal_error' : ($fromShutdown ? 'early_shutdown' : 'normal_shutdown'),
                // Keeps the key present (as null) even when no caller reported it,
                // so the versioned event schema stays stable for consumers.
                'external_api_total_ms' => array_key_exists('external_api_total_ms', self::$workload) ? self::$workload['external_api_total_ms'] : null,
                'total_duration_ms' => round($duration, 3),
                'db_total_ms' => $summary['db_total_ms'],
                'db_query_count' => $summary['db_query_count'],
                'db_slow_query_count' => $summary['db_slow_query_count'],
                'top_query_fingerprints' => $summary['top_query_fingerprints'],
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ));
            if (self::shouldEmit($event)) {
                self::emitBounded($event);
            }
        } catch (Throwable $e) {
            // Never emit the exception text: it can include SQL or request data.
            error_log(' PERF {"type":"observability_failure","component":"application_summary"}');
        }
        self::$earlyStartedAt = null;
        self::$earlyTrace = null;
    }

    public static function normalizeSql($sql)
    {
        // preg_replace() returns null when PCRE hits its backtrack or recursion
        // limit on a pathological statement. Returning '' drops that statement
        // from fingerprinting instead of passing null into strtoupper().
        $steps = array(
            array('/\/\*.*?\*\//s', ' '),
            array('/--[^\r\n]*/', ' '),
            array("/'(?:''|\\\\.|[^'])*'/s", '?'),
            array('/"(?:""|\\\\.|[^"])*"/s', '?'),
            array('/\b(?:0x[0-9a-f]+|\d+(?:\.\d+)?)\b/i', '?'),
        );
        $sql = (string) $sql;
        foreach ($steps as $step) {
            $sql = preg_replace($step[0], $step[1], $sql);
            if ($sql === null) {
                return '';
            }
        }
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return $sql === null ? '' : strtoupper($sql);
    }

    private static function querySummary($db)
    {
        $queries = is_object($db) && isset($db->queries) && is_array($db->queries) ? $db->queries : array();
        $times = is_object($db) && isset($db->query_times) && is_array($db->query_times) ? $db->query_times : array();
        $threshold = (float) self::setting('OBSERVABILITY_SLOW_QUERY_MS', '250');
        $grouped = array();
        $total = 0.0;
        $slowQueries = 0;
        foreach ($queries as $i => $sql) {
            $ms = max(0, ((float) ($times[$i] ?? 0)) * 1000);
            // Totals cover every executed query so they stay consistent with
            // db_query_count. Only fingerprinting skips unnormalizable SQL.
            $total += $ms;
            if ($ms >= $threshold) {
                $slowQueries++;
            }
            $normalized = self::normalizeSql($sql);
            if ($normalized === '') {
                continue;
            }
            $fingerprint = hash('sha256', $normalized);
            if (!isset($grouped[$fingerprint])) {
                $grouped[$fingerprint] = array(
                    'fingerprint' => $fingerprint,
                    // The normalized form contains no literal values and lets a
                    // MySQL DIGEST_TEXT be mapped without logging bindings.
                    'normalized_query' => substr($normalized, 0, max(64, min(512, (int) self::setting('OBSERVABILITY_MAX_NORMALIZED_QUERY_BYTES', '512')))),
                    'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'slow_count' => 0,
                );
            }
            $grouped[$fingerprint]['count']++;
            $grouped[$fingerprint]['total_ms'] += $ms;
            $grouped[$fingerprint]['max_ms'] = max($grouped[$fingerprint]['max_ms'], $ms);
            if ($ms >= $threshold) $grouped[$fingerprint]['slow_count']++;
        }
        usort($grouped, function ($a, $b) { return $b['total_ms'] <=> $a['total_ms']; });
        $top = array_slice($grouped, 0, max(1, min(20, (int) self::setting('OBSERVABILITY_TOP_QUERIES', '8'))));
        foreach ($top as &$row) {
            $row['total_ms'] = round($row['total_ms'], 3);
            $row['avg_ms'] = round($row['total_ms'] / max(1, $row['count']), 3);
            $row['max_ms'] = round($row['max_ms'], 3);
        }
        unset($row);
        return array('db_total_ms' => round($total, 3), 'db_query_count' => count($queries), 'db_slow_query_count' => $slowQueries, 'top_query_fingerprints' => $top);
    }

    private static function shouldEmit(array $event)
    {
        if (self::setting('OBSERVABILITY_FORCE_LOG', '0') === '1') return true;
        if ($event['db_slow_query_count'] > 0 || $event['total_duration_ms'] >= (float) self::setting('OBSERVABILITY_SLOW_REQUEST_MS', '1000')) return true;
        $rate = max(0.0, min(1.0, (float) self::setting('OBSERVABILITY_NORMAL_SAMPLE_RATE', '0.01')));
        return mt_rand() / mt_getrandmax() < $rate;
    }

    private static function emitBounded(array $event)
    {
        $limit = max(1024, min(65536, (int) self::setting('OBSERVABILITY_MAX_EVENT_BYTES', '16384')));
        $json = json_encode($event, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        if (strlen($json) > $limit) {
            $event['top_query_fingerprints'] = array_slice($event['top_query_fingerprints'], 0, 1);
            $event['truncated'] = true;
            $json = json_encode($event, JSON_UNESCAPED_SLASHES);
        }
        error_log(' PERF ' . $json);
    }

    private static function enabled() { return self::setting('OBSERVABILITY_ENABLED', '0') === '1'; }

    /**
     * The pre_system hook runs before application/config/database.php is loaded,
     * so env() does not exist yet at that point. Loading the helper here keeps
     * every hook reading the same .env values. Without it, pre_system falls back
     * to getenv(), which under PHP-FPM only sees variables a previous request on
     * the same worker happened to putenv() - making begin_early() fire
     * inconsistently and total_duration_ms start from a different point.
     */
    private static function loadEnvHelper()
    {
        if (self::$envHelperLoaded) {
            return;
        }
        self::$envHelperLoaded = true;
        if (function_exists('env') || !defined('APPPATH')) {
            return;
        }
        $helper = APPPATH . 'helpers/env_helper.php';
        if (is_file($helper)) {
            require_once $helper;
        }
    }

    private static function setting($key, $default)
    {
        self::loadEnvHelper();
        if (function_exists('env')) {
            $value = env($key, $default);
            return $value === null ? (string) $default : (string) $value;
        }
        $value = getenv($key);
        return $value === false ? (string) $default : (string) $value;
    }
    private static function incomingTrace()
    {
        $configured = self::setting('OBSERVABILITY_TRACE_HEADER', 'X-Trace-ID');
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $configured));
        return (string) ($_SERVER[$key] ?? $_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    }
    private static function safeToken($value) { return preg_replace('/[^A-Za-z0-9_.:\/-]/', '_', substr((string) $value, 0, 160)); }
    private static function validTrace($value) { return is_string($value) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $value); }
    private static function newTrace() { return bin2hex(random_bytes(16)); }
}
