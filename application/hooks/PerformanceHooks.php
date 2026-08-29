<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Performance_observer.php';

/** Lightweight lifecycle hooks; all expensive work is opt-in at runtime. */
class PerformanceHooks
{
    public function early()
    {
        Performance_observer::begin_early();
    }

    public function begin()
    {
        Performance_observer::begin();
    }

    public function finish()
    {
        Performance_observer::finish();
    }
}
