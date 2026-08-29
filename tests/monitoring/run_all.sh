#!/usr/bin/env sh
# Runs every monitoring check plus the application PHPUnit suite.
# A failing check no longer aborts the run: all checks execute, then the summary
# reports each result and the script exits non-zero if anything failed.
set -u
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"

failed=""
run() {
    printf '\n$ %s\n' "$*"
    if "$@"; then
        return 0
    fi
    status=$?
    printf 'FAILED (exit %s): %s\n' "$status" "$*"
    failed="${failed}${failed:+
}$*"
    return 0
}

run tests/monitoring/resource_snapshot_test.sh
run tests/monitoring/controlled_incident_demo.sh
run tests/monitoring/mysql_diagnostics_test.sh
run tests/monitoring/mysql_diagnostics_live_test.sh
run python3 tests/monitoring/config_validation_test.py
run python3 tests/monitoring/incident_report_test.py
run python3 tests/monitoring/overhead_test.py
run php -d assert.exception=1 tests/monitoring/performance_observer_test.php
run tests/monitoring/performance_observer_shutdown_test.sh
run php -r 'define("BASEPATH", __DIR__); require "vendor/bin/phpunit";' \
    tests --testdox --do-not-cache-result

printf '\n===== summary =====\n'
if [ -z "$failed" ]; then
    echo 'run_all: PASS (all checks succeeded)'
    exit 0
fi
echo 'run_all: FAIL — the following checks did not succeed:'
printf '%s\n' "$failed" | sed 's/^/  - /'
exit 1
