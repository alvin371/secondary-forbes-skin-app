#!/usr/bin/env sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/mysql-diagnostics-test.XXXXXX")
trap 'rm -rf "$TEST_DIR"' EXIT HUP INT TERM
mkdir -p "$TEST_DIR/bin" "$TEST_DIR/state"
cat > "$TEST_DIR/bin/mysql" <<'FAKE_MYSQL'
#!/usr/bin/env sh
case "$*" in
  *"VERSION()"*) printf '%b\n' 'version\t8.0.36' 'version_comment\tMySQL Community' 'performance_schema\tON' 'time_zone\tSYSTEM' ;;
  *"SHOW VARIABLES"*) printf '%b\n' 'slow_query_log\tOFF' 'long_query_time\t10.000000' 'performance_schema\tON' ;;
  *"SHOW GLOBAL STATUS"*) printf '%b\n' 'Questions\t100' 'Rows_read\t5000' 'Created_tmp_disk_tables\t2' ;;
  *"events_statements_summary_by_digest"*) printf '%b\n' 'app\tabc\tSELECT * FROM T WHERE ID = ?\t10\t100\t10\t20\t2\t500\t100\t10\t1\t1\t2\t0\t0\t0\t0' ;;
  *"events_statements_current"*) printf '%b\n' '1\t2\tabc\tSELECT * FROM T WHERE ID = ?\t5\t50\t1\t0\t0\t0\t0\t0\t0\t0' ;;
  *"information_schema.PROCESSLIST"*) printf '%b\n' 'app\tweb\tQuery\trunning\t1' ;;
  *"information_schema.innodb_trx"*) printf '%b\n' '1\tRUNNING\t2026-01-01\t\t1\t0\t0' ;;
  *"data_lock_waits"*) exit 0 ;;
  *"SHOW ENGINE INNODB STATUS"*) printf '%b\n' 'InnoDB\t\tstatus' ;;
  *) exit 0 ;;
esac
FAKE_MYSQL
chmod +x "$TEST_DIR/bin/mysql"
PATH="$TEST_DIR/bin:$PATH" MYSQL_MONITOR_STATE_FILE="$TEST_DIR/state/counters.json" MYSQL_MONITOR_TIMEOUT_SECONDS=2 "$ROOT/tools/monitoring/mysql_diagnostics.sh" > "$TEST_DIR/result.json"
jq -e '.type == "mysql_diagnostics" and .mysql.performance_schema == "ON" and .counters.snapshot.Questions == 100 and .digests.rows[0].digest == "abc" and .collection.status == "success"' "$TEST_DIR/result.json" >/dev/null || { jq . "$TEST_DIR/result.json"; exit 1; }
echo "mysql_diagnostics_test: PASS"
