#!/usr/bin/env sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
output=$(php -d error_log= -d log_errors=1 "$ROOT/tests/monitoring/performance_observer_shutdown_test.php" 2>&1)
printf '%s\n' "$output" | sed -n 's/^ PERF //p' | jq -e 'select(.execution_status == "unknown" and .shutdown_reason == "early_shutdown")' >/dev/null
echo "performance_observer_shutdown_test: PASS exit_is_unknown"
