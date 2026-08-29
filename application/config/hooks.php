<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	https://codeigniter.com/userguide3/general/hooks.html
|
*/

/*
 * Observability is intentionally controlled at runtime by OBSERVABILITY_ENABLED.
 * Enabling hooks only loads the tiny lifecycle wrapper; it emits no events unless
 * explicitly enabled in the deployment environment.
 */
$hook['pre_system'][] = array(
    'class' => 'PerformanceHooks',
    'function' => 'early',
    'filename' => 'PerformanceHooks.php',
    'filepath' => 'hooks',
);
$hook['post_controller_constructor'][] = array(
    'class' => 'PerformanceHooks',
    'function' => 'begin',
    'filename' => 'PerformanceHooks.php',
    'filepath' => 'hooks',
);
$hook['post_system'][] = array(
    'class' => 'PerformanceHooks',
    'function' => 'finish',
    'filename' => 'PerformanceHooks.php',
    'filepath' => 'hooks',
);
