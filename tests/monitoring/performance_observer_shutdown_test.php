<?php
declare(strict_types=1);
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__, 2) . '/application/');
define('ENVIRONMENT', 'testing');
function env($key, $default = null) { return $key === 'OBSERVABILITY_ENABLED' ? '1' : ($key === 'OBSERVABILITY_FORCE_LOG' ? '1' : $default); }
function is_cli() { return true; }
class ShutdownRouter { public $class = 'Test'; public $method = 'exit_path'; }
class ShutdownDb { public $queries = []; public $query_times = []; }
class ShutdownCi { public $router; public $db; public function __construct() { $this->router = new ShutdownRouter(); $this->db = new ShutdownDb(); } }
$shutdownCi = new ShutdownCi();
function get_instance() { global $shutdownCi; return $shutdownCi; }
require APPPATH . 'libraries/Performance_observer.php';
Performance_observer::begin();
exit(0);
