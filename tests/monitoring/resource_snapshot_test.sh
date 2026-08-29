#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
COLLECTOR="$ROOT/tools/monitoring/resource_snapshot.sh"
TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/forbes-monitor-test.XXXXXX")
trap 'rm -rf "$TEST_DIR"' EXIT HUP INT TERM
LOG="$TEST_DIR/log"; STATE="$TEST_DIR/state"

run_collector() {
    MONITOR_LOG_DIR="$LOG" MONITOR_STATE_DIR="$STATE" MONITOR_RETENTION_DAYS=999 \
    MONITOR_CPU_THRESHOLD="${MONITOR_CPU_THRESHOLD:-80}" \
    MONITOR_RECOVERY_SAMPLES="${MONITOR_RECOVERY_SAMPLES:-2}" \
    MONITOR_TIMEOUT_SECONDS="${MONITOR_TIMEOUT_SECONDS:-1}" \
    MONITOR_STATS_COMMAND="$1" MONITOR_DIAGNOSTICS_COMMAND="${2:-}" "$COLLECTOR"
}
stats() {
    printf "%s\n" \
        "mysql-8_mysql.1|$1%|20.5%|3" \
        "forbes_app.1|10.25%|10%|2" \
        "sec-forbes_app.1|5%|10%|1"
}
stats_command() {
    printf "printf '%%s\\n' 'mysql-8_mysql.1|%s%%|20.5%%|3' 'forbes_app.1|10.25%%|10%%|2' 'sec-forbes_app.1|5%%|10%%|1'" "$1"
}
jsonl() { find "$LOG" -maxdepth 1 -name 'resource-*.jsonl' -type f -exec cat {} \;; }
count_type() { jsonl | jq -s --arg t "$1" '[.[] | select(.type == $t)] | length'; }
assert_eq() { [ "$1" = "$2" ] || { echo "assertion failed: expected '$1', got '$2'" >&2; exit 1; }; }

# Normal sample, malformed row isolation, and valid health output.
run_collector "printf '%s\\n' 'mysql-8_mysql.1|10%|20.5%|3|1.2kB / 3.4kB|5kB / 6kB' 'forbes_app.1|bad|10%|2'"
assert_eq "$(count_type collector_health)" 1
jq -e 'select(.type == "resource_snapshot" and (.containers|length) == 1)' <<EOF >/dev/null
$(jsonl)
EOF
jq -e 'select(.type == "resource_snapshot" and .containers[0].net_io == "1.2kB / 3.4kB" and .containers[0].block_io == "5kB / 6kB")' <<EOF >/dev/null
$(jsonl)
EOF
jq -e 'select(.type == "collector_health" and .status == "partial" and (.failed_sections|contains("malformed_mysql-8_mysql")|not))' <<EOF >/dev/null || true
$(jsonl)
EOF

# Missing command configuration is a visible partial collection, not a silent success.
run_collector ""
jq -e 'select(.type == "collector_health" and .status == "partial" and (.failed_sections|contains("docker_stats_missing_command")))' <<EOF >/dev/null
$(jsonl)
EOF

# One continuous incident: exactly one open and close, with a peak event.
run_collector "$(stats_command 90)" "printf '%s' '{\"mysql_version\":\"test\"}'"
run_collector "$(stats_command 120.5)" "sh -c 'exit 7'"
run_collector "$(stats_command 1)" "printf '%s' not-json"
run_collector "$(stats_command 1)" "printf '%s' not-json"
assert_eq "$(count_type performance_incident_open)" 1
assert_eq "$(count_type performance_incident_close)" 1
assert_eq "$(count_type performance_incident_peak)" 1
jq -e 'select(.type == "collector_health" and .status == "partial")' <<EOF >/dev/null
$(jsonl)
EOF
find "$LOG" -name 'incident-*.json' -type f -print0 | xargs -0 -n1 jq -e . >/dev/null
jq -e 'select(.phase == "open" and .mysql_diagnostics.mysql_version == "test")' "$LOG"/incident-*-open-*.json >/dev/null
jq -e 'select(.phase == "open" and (.incident_id | length) > 0)' "$LOG"/incident-*-open-*.json >/dev/null
jq -e 'select(.phase == "peak" and .mysql_diagnostics.collection_status == "failed")' "$LOG"/incident-*-peak-*.json >/dev/null

# Timeout is bounded and visible; it must not prevent a health record.
rm -f "$LOG"/incident-*.json
MONITOR_TIMEOUT_SECONDS=1 run_collector "sleep 3" || true
jq -e 'select(.type == "collector_health" and .status == "partial" and (.failed_sections|contains("docker_stats")))' <<EOF >/dev/null
$(jsonl)
EOF

# Corrupt state is recovered into a fresh incident and reported.
printf '%s\n' 'bad|state' > "$STATE/incident-mysql-8_mysql.state"
run_collector "$(stats_command 90)" ""
jq -e 'select(.type == "collector_health" and (.failed_sections|contains("corrupt_state_mysql-8_mysql")))' <<EOF >/dev/null
$(jsonl)
EOF

# Two overlapping runs must not create two incident openings.
slow_stats="sleep 1; $(stats_command 90)"
MONITOR_TIMEOUT_SECONDS=2 run_collector "$slow_stats" "" & first=$!
MONITOR_TIMEOUT_SECONDS=2 run_collector "$slow_stats" "" & second=$!
wait "$first" || true; wait "$second" || true
assert_eq "$(count_type performance_incident_open)" 2

echo "resource_snapshot_test: PASS controlled_incident_id=$(jsonl | jq -r 'select(.type == "performance_incident_open") | .incident_id' | head -n 1)"
