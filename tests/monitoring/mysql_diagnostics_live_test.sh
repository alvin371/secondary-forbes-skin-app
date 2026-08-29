#!/usr/bin/env sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)

if [ "${RUN_MYSQL_LIVE_TESTS:-0}" != "1" ]; then
    echo "mysql_diagnostics_live_test: SKIP (set RUN_MYSQL_LIVE_TESTS=1 with a restricted account)"
    exit 0
fi

test_dir=$(mktemp -d "${TMPDIR:-/tmp}/mysql-diagnostics-live.XXXXXX")
trap 'rm -rf "$test_dir"' EXIT HUP INT TERM
: "${MYSQL_MONITOR_HOST:?MYSQL_MONITOR_HOST is required}"
: "${MYSQL_MONITOR_USER:?MYSQL_MONITOR_USER is required}"
: "${MYSQL_MONITOR_PASSWORD_FILE:?MYSQL_MONITOR_PASSWORD_FILE is required}"
MYSQL_MONITOR_STATE_FILE="$test_dir/counters.json" \
    "$ROOT/tools/monitoring/mysql_diagnostics.sh" > "$test_dir/result.json"

python3 - "$test_dir/result.json" <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
assert data.get('type') == 'mysql_diagnostics'
assert 'password' not in json.dumps(data).lower()
assert 'MYSQL_MONITOR_PASSWORD' not in json.dumps(data)
assert isinstance(data.get('collection'), dict)
print('mysql_diagnostics_live_test: PASS schema_and_secret_redaction')
PY
