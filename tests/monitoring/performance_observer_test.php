<?php
declare(strict_types=1);

define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('ENVIRONMENT', 'testing');

function env($key, $default = null) {
    $values = [
        'OBSERVABILITY_ENABLED' => '1',
        'OBSERVABILITY_APPLICATION' => 'test-app',
        'OBSERVABILITY_NORMAL_SAMPLE_RATE' => '1',
        'OBSERVABILITY_MAX_EVENT_BYTES' => '16384',
        'OBSERVABILITY_SLOW_QUERY_MS' => '15',
    ];
    return $values[$key] ?? $default;
}
function is_cli() { return true; }

class ObserverRouter { public $class = 'Test'; public $method = 'run'; }
class ObserverDb { public $queries = []; public $query_times = []; }
class ObserverCi { public $router; public $db; public function __construct() { $this->router = new ObserverRouter(); $this->db = new ObserverDb(); } }
$observerCi = new ObserverCi();
function get_instance() { global $observerCi; return $observerCi; }

$failures = [];
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures[] = $label; }
}

require APPPATH . 'libraries/Performance_observer.php';

// --- normalisation -------------------------------------------------------
check('literals are replaced', Performance_observer::normalizeSql(
    "SELECT * FROM users WHERE id = 42 AND email = 'private@example.test'"
) === "SELECT * FROM USERS WHERE ID = ? AND EMAIL = ?");
check('comments are stripped', Performance_observer::normalizeSql(
    "SELECT a /* secret */ FROM t -- trailing\n WHERE b = 7"
) === "SELECT A FROM T WHERE B = ?");
check('empty sql normalises to empty string', Performance_observer::normalizeSql('   ') === '');

// --- emitted event -------------------------------------------------------
$observerCi->db->queries = [
    "SELECT * FROM users WHERE id = 42",
    "SELECT * FROM users WHERE id = 43",
    "SELECT name FROM brand WHERE code = 'BKA'",
];
$observerCi->db->query_times = [0.010, 0.020, 0.030];

Performance_observer::begin_early();
Performance_observer::begin();
Performance_observer::set_workload([
    'job_or_command_name' => 'test-job',
    'items_processed' => 2,
    'items_failed' => 0,
    'not_an_allowed_field' => 'should be dropped',
]);

$captured = tempnam(sys_get_temp_dir(), 'perf-observer-');
ini_set('log_errors', '1');
ini_set('error_log', $captured);
Performance_observer::finish();
ini_restore('error_log');

$lines = array_values(array_filter(
    explode("\n", (string) file_get_contents($captured)),
    static fn(string $line): bool => strpos($line, ' PERF ') !== false
));
unlink($captured);

check('exactly one PERF event was emitted', count($lines) === 1);
$json = count($lines) === 1 ? substr($lines[0], strpos($lines[0], ' PERF ') + 6) : '{}';
$event = json_decode($json, true);
check('event is valid JSON', is_array($event));
$event = is_array($event) ? $event : [];

check('application comes from config', ($event['application'] ?? null) === 'test-app');
check('workload field is carried', ($event['job_or_command_name'] ?? null) === 'test-job');
check('items_processed is carried', ($event['items_processed'] ?? null) === 2);
check('unlisted workload keys are dropped', !array_key_exists('not_an_allowed_field', $event));
check('execution_status is completed', ($event['execution_status'] ?? null) === 'completed');
check('db_query_count counts every query', ($event['db_query_count'] ?? null) === 3);
check('db_slow_query_count uses the threshold', ($event['db_slow_query_count'] ?? null) === 2);
check('duplicate memory_peak key is gone', !array_key_exists('memory_peak', $event));
check('memory_peak_bytes is present', is_int($event['memory_peak_bytes'] ?? null));
check('external_api_total_ms key is always present', array_key_exists('external_api_total_ms', $event));

$fingerprints = $event['top_query_fingerprints'] ?? [];
check('queries are grouped by fingerprint', count($fingerprints) === 2);
$byCount = [];
foreach ($fingerprints as $row) { $byCount[$row['count']] = $row; }
check('identical shapes are collapsed', isset($byCount[2]));
check('fingerprint is a sha256 hex digest', (bool) preg_match('/^[0-9a-f]{64}$/', $fingerprints[0]['fingerprint'] ?? ''));

// The whole point of the observer: no literal values may leave the process.
check('no literal id leaks', strpos($json, '42') === false || strpos($json, 'ID = ?') !== false);
check('no literal string leaks', strpos($json, 'BKA') === false);
check('no raw email leaks', strpos($json, 'private@example.test') === false);

if ($failures) {
    fwrite(STDERR, "performance_observer_test: FAIL\n");
    foreach ($failures as $failure) { fwrite(STDERR, "  - {$failure}\n"); }
    exit(1);
}
echo "performance_observer_test: PASS ({$checks} checks)\n";
