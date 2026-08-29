#!/usr/bin/env sh
# Safe local vertical-slice demo. It never connects to MySQL or Docker.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
if [ -n "${CONTROLLED_INCIDENT_ARTIFACT_DIR:-}" ]; then
    BASE=$CONTROLLED_INCIDENT_ARTIFACT_DIR
    KEEP=1
else
    BASE=$(mktemp -d "${TMPDIR:-/tmp}/forbes-controlled-incident.XXXXXX")
    KEEP=0
fi
mkdir -p "$BASE/log" "$BASE/state"
cleanup() { [ "$KEEP" -eq 1 ] || rm -rf "$BASE"; }
trap cleanup EXIT HUP INT TERM

diag_state="$BASE/diag-count"
printf '%s' 100 > "$diag_state"
diag_cmd="n=\$(cat '$diag_state'); next=\$((n+10)); printf '{\"schema_version\":\"1\",\"type\":\"mysql_diagnostics\",\"mysql\":{\"version\":\"8.0-demo\"},\"counters\":{\"snapshot\":{\"Questions\":%s}},\"digests\":{\"status\":\"ok\",\"rows\":[{\"schema\":\"app\",\"digest\":\"demo\",\"digest_text\":\"SELECT * FROM T WHERE ID = ?\",\"execution_count\":%s,\"total_time_ms\":%s,\"avg_time_ms\":10,\"max_time_ms\":25,\"rows_examined\":%s,\"rows_sent\":%s,\"rows_affected\":0,\"lock_time_ms\":%s,\"tmp_disk_tables\":%s}]},\"transactions\":{\"status\":\"ok\",\"rows\":[]},\"lock_waits\":{\"status\":\"ok\",\"rows\":[]},\"collection\":{\"status\":\"success\"}}' \"\$((n*1000))\" \"\$n\" \"\$((n*10))\" \"\$((n*100))\" \"\$n\" \"\$((n/10))\" \"\$((n/100))\"; printf '%s' \"\$next\" > '$diag_state'"
stats() { printf "printf '%%s\\n' 'mysql-8_mysql|%s%%|20%%|3'" "$1"; }
run() {
    stats_cmd=$(stats "$1")
    MONITOR_LOG_DIR="$BASE/log" MONITOR_STATE_DIR="$BASE/state" \
    MONITOR_SERVICES=mysql-8_mysql MONITOR_CPU_THRESHOLD=80 \
    MONITOR_RECOVERY_SAMPLES=2 MONITOR_ACTIVE_EVERY=1 \
    MONITOR_DIAGNOSTICS_COMMAND="$diag_cmd" MONITOR_TIMEOUT_SECONDS=2 \
    MONITOR_STATS_COMMAND="$stats_cmd" "$ROOT/tools/monitoring/resource_snapshot.sh"
}
run 90
run 120
run 1
run 1

resource=$(find "$BASE/log" -name 'resource-*.jsonl' -type f | head -n 1)
incident_id=$(jq -r 'select(.type == "performance_incident_open") | .incident_id' "$resource" | head -n 1)
open_ts=$(jq -r --arg id "$incident_id" 'select(.type == "performance_incident_open" and .incident_id == $id) | .ts' "$resource" | head -n 1)
printf '%s\n' "{\"type\":\"request_performance\",\"timestamp_utc\":\"$open_ts\",\"application\":\"forbes\",\"route_or_command\":\"cli:endorse_refresh\",\"db_total_ms\":300,\"db_query_count\":30,\"top_query_fingerprints\":[{\"fingerprint\":\"app-demo\",\"normalized_query\":\"select * from t where id = ?\",\"count\":30,\"total_ms\":300}]}" > "$BASE/app.jsonl"
python3 "$ROOT/tools/monitoring/incident_report.py" --resource "$resource" \
    --evidence-dir "$BASE/log" --app-log "$BASE/app.jsonl" --incident-id "$incident_id" \
    --output-json "$BASE/incident.json" --output-md "$BASE/incident.md"
open=$(jq -r --arg id "$incident_id" 'select(.type == "performance_incident_open" and .incident_id == $id) | .ts' "$resource" | head -n 1)
peak=$(jq -r --arg id "$incident_id" 'select(.type == "performance_incident_peak" and .incident_id == $id) | .ts' "$resource" | head -n 1)
close=$(jq -r --arg id "$incident_id" 'select(.type == "performance_incident_close" and .incident_id == $id) | .ts' "$resource" | head -n 1)
printf 'controlled_incident_id=%s\nopened_at=%s\npeak_at=%s\nclosed_at=%s\nresource_log=%s\nevidence_files=%s\nreport_json=%s\nreport_markdown=%s\ndiagnostic_status=%s\n' \
    "$incident_id" "$open" "$peak" "$close" "$resource" \
    "$(find "$BASE/log" -name 'incident-*.json' -type f | sort | tr '\n' ',')" \
    "$BASE/incident.json" "$BASE/incident.md" \
    "$(jq -r '.evidence_completeness.status' "$BASE/incident.json")"
