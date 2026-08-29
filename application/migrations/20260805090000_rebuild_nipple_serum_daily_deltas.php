<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Rebuild_nipple_serum_daily_deltas extends CI_Migration
{
    public function up()
    {
        // Historical data repair must be an explicitly reviewed operational job,
        // never an automatic production schema migration. The original repair
        // rewrote campaign 27 logs and rollups without a preceding audit trail.
        // Leave this timestamp in place so installations below it can progress
        // safely to the additive migrations that follow.
    }

    public function down()
    {
        // No automatic rollback is required because up() is intentionally inert.
    }
}
