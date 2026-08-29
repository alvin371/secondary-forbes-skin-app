#!/usr/bin/env sh
# Bounded, log-first host/container incident collector. Diagnostics are opt-in.
set -u

MONITOR_LOG_DIR=${MONITOR_LOG_DIR:-/var/log/forbes-monitor}
MONITOR_STATE_DIR=${MONITOR_STATE_DIR:-/var/lib/forbes-monitor}
MONITOR_CPU_THRESHOLD=${MONITOR_CPU_THRESHOLD:-80}
MONITOR_RECOVERY_SAMPLES=${MONITOR_RECOVERY_SAMPLES:-3}
MONITOR_ACTIVE_EVERY=${MONITOR_ACTIVE_EVERY:-4}
MONITOR_TIMEOUT_SECONDS=${MONITOR_TIMEOUT_SECONDS:-8}
MONITOR_RETENTION_DAYS=${MONITOR_RETENTION_DAYS:-14}
MONITOR_MAX_EVIDENCE_BYTES=${MONITOR_MAX_EVIDENCE_BYTES:-262144}
MONITOR_MAX_DIAGNOSTIC_BYTES=${MONITOR_MAX_DIAGNOSTIC_BYTES:-131072}
MONITOR_STATS_COMMAND=${MONITOR_STATS_COMMAND-"docker stats --no-stream --format '{{.Name}}|{{.CPUPerc}}|{{.MemPerc}}|{{.PIDs}}|{{.NetIO}}|{{.BlockIO}}'"}
MONITOR_DIAGNOSTICS_COMMAND=${MONITOR_DIAGNOSTICS_COMMAND:-}
MONITOR_SERVICES=${MONITOR_SERVICES:-"mysql-8_mysql forbes_app sec-forbes_app"}
MONITOR_VERSION=${MONITOR_VERSION:-3}

mkdir -p "$MONITOR_LOG_DIR" "$MONITOR_STATE_DIR" 2>/dev/null || exit 1
lock="$MONITOR_STATE_DIR/collector.lock"
mkdir "$lock" 2>/dev/null || exit 0
tmpdir="$MONITOR_STATE_DIR/.tmp.$$"
mkdir "$tmpdir" 2>/dev/null || { rmdir "$lock" 2>/dev/null || true; exit 1; }
cleanup() { rm -rf "$tmpdir" 2>/dev/null || true; rmdir "$lock" 2>/dev/null || true; }
trap cleanup EXIT HUP INT TERM

ts=$(date -u +%Y-%m-%dT%H:%M:%SZ); started=$(date +%s)
jsonl="$MONITOR_LOG_DIR/resource-$(date -u +%F).jsonl"; failures=""; last_successful=""
[ -r "$MONITOR_STATE_DIR/last-successful-collection" ] && last_successful=$(sed -n '1p' "$MONITOR_STATE_DIR/last-successful-collection")

add_failure() { case ",${failures}," in *",$1,"*) ;; *) failures="${failures}${failures:+,}$1" ;; esac; }
run_to_file() {
    label=$1 command=$2 output=$3 error=$4
    if [ -z "$command" ]; then
        : > "$output"; : > "$error"; add_failure "${label}_missing_command"; return 127
    fi
    if command -v timeout >/dev/null 2>&1; then
        timeout "$MONITOR_TIMEOUT_SECONDS" sh -c "$command" >"$output" 2>"$error"
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c 'import subprocess,sys
try:
    subprocess.run(["sh", "-c", sys.argv[2]], check=True, timeout=float(sys.argv[1]))
except subprocess.TimeoutExpired:
    sys.exit(124)
except subprocess.CalledProcessError as exc:
    sys.exit(exc.returncode)' "$MONITOR_TIMEOUT_SECONDS" "$command" >"$output" 2>"$error"
    else
        sh -c "$command" >"$output" 2>"$error"
    fi
    rc=$?; [ "$rc" -eq 0 ] || add_failure "$label"; return "$rc"
}
json_escape() {
    if command -v python3 >/dev/null 2>&1; then python3 -c 'import json,sys; print(json.dumps(sys.stdin.read(), ensure_ascii=False))' 2>/dev/null || printf '""'
    else input=${1:-$(cat)}; printf '%s' "$input" | sed 's/\\/\\\\/g; s/"/\\"/g' | awk '{printf "\"%s\"",$0}'; fi
}
json_value() {
    if command -v python3 >/dev/null 2>&1 && printf '%s' "$1" | python3 -c 'import json,sys; json.load(sys.stdin)' >/dev/null 2>&1; then printf '%s' "$1"; else printf '%s' "$1" | json_escape; fi
}
line() { printf '%s\n' "$1" >> "$jsonl"; }
number() { printf '%s' "$1" | grep -Eq '^[0-9]+([.][0-9]+)?$'; }
gt() { awk -v a="$1" -v b="$2" 'BEGIN { exit !(a > b) }'; }
escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
safe_name() { printf '%s' "$1" | sed 's/[^A-Za-z0-9_.-]/_/g'; }

stats_out="$tmpdir/stats.out"; stats_err="$tmpdir/stats.err"; stats=""
run_to_file docker_stats "$MONITOR_STATS_COMMAND" "$stats_out" "$stats_err" || true
[ -r "$stats_out" ] && stats=$(cat "$stats_out")
load=$(awk '{print $1" "$2" "$3}' /proc/loadavg 2>/dev/null || true)
memory=$(free -b 2>/dev/null | awk '/^Mem:/ {print "used=" $3 ",total=" $2}' || true)
snapshot=$(printf '%s\n' "$stats" | awk -F'|' -v ts="$ts" -v load="$load" -v mem="$memory" -v fail="$failures" -v version="$MONITOR_VERSION" '
function e(x){gsub(/\\/,"\\\\",x);gsub(/"/,"\\\"",x);return x}
BEGIN{printf "{\"schema_version\":\"1\",\"type\":\"resource_snapshot\",\"ts\":\"%s\",\"host_load\":\"%s\",\"host_memory\":\"%s\",\"collector_version\":\"%s\",\"failed_sections\":\"%s\",\"containers\":[",e(ts),e(load),e(mem),e(version),e(fail);first=1}
NF==4 || NF==6{c=$2;m=$3;sub(/%$/,"",c);sub(/%$/,"",m);if(c~/^[0-9]+([.][0-9]+)?$/&&m~/^[0-9]+([.][0-9]+)?$/&&$4~/^[0-9]+$/){if(!first)printf ",";printf "{\"name\":\"%s\",\"cpu_percent\":%s,\"memory_percent\":%s,\"pids\":%s",e($1),c,m,$4;if(NF==6)printf ",\"net_io\":\"%s\",\"block_io\":\"%s\"",e($5),e($6);printf "}";first=0}}
END{print "]}"}')
line "$snapshot"

evidence() {
    service=$1; phase=$2; incident_id=${3:-unknown}; safe_service=$(safe_name "$service")
    file="$MONITOR_LOG_DIR/incident-${safe_service}-${phase}-$(date -u +%Y%m%dT%H%M%SZ)-$$.json"
    diag='{"collection_status":"unavailable"}'
    if [ -n "$MONITOR_DIAGNOSTICS_COMMAND" ]; then
        run_to_file mysql_diagnostics "$MONITOR_DIAGNOSTICS_COMMAND" "$tmpdir/diag.out" "$tmpdir/diag.err" || true
        raw=$(cat "$tmpdir/diag.out" 2>/dev/null | head -c "$MONITOR_MAX_DIAGNOSTIC_BYTES")
        [ -n "$raw" ] && diag=$(json_value "$raw") || diag='{"collection_status":"failed","detail":"no output"}'
    fi
    processes='[]'
    if command -v ps >/dev/null 2>&1; then processes=$(ps -eo pid,ppid,comm,%cpu,%mem,etime --sort=-%cpu 2>/dev/null | head -n 41 | json_escape); fi
    payload=$(printf '{"schema_version":"1","captured_at":"%s","collector_version":"%s","incident_id":"%s","service_name":"%s","phase":"%s","host":{"load":"%s","memory":"%s"},"processes":%s,"mysql_diagnostics":%s,"failed_sections":"%s"}' \
        "$(escape "$ts")" "$MONITOR_VERSION" "$(escape "$incident_id")" "$(escape "$service")" "$phase" "$(escape "$load")" "$(escape "$memory")" "$processes" "$diag" "$(escape "$failures")")
    payload_bytes=$(printf '%s' "$payload" | wc -c | tr -d ' ')
    if [ "$payload_bytes" -gt "$MONITOR_MAX_EVIDENCE_BYTES" ]; then
        payload=$(printf '{"schema_version":"1","captured_at":"%s","collector_version":"%s","incident_id":"%s","service_name":"%s","phase":"%s","truncated":true,"failed_sections":"%s"}' \
            "$(escape "$ts")" "$MONITOR_VERSION" "$(escape "$incident_id")" "$(escape "$service")" "$phase" "$(escape "$failures")")
    fi
    printf '%s\n' "$payload" > "$file.$$" && mv "$file.$$" "$file"; printf '%s' "$file"
}
event() {
    event_type=$1; service=$2; incident_id=$3; began=$4; cpu=$5; mem=$6; pids=$7; peak_cpu=$8; peak_mem=$9; evidence_path=${10:-}
    status=success; [ -n "$failures" ] && status=partial
    printf '{"schema_version":"1","type":"%s","ts":"%s","service_name":"%s","incident_id":"%s","started_at":"%s","cpu_percent":%s,"memory_percent":%s,"pids":%s,"peak_cpu_percent":%s,"peak_memory_percent":%s,"evidence_path":"%s","collector_status":"%s","failed_sections":"%s"}' \
        "$(escape "$event_type")" "$(escape "$ts")" "$(escape "$service")" "$(escape "$incident_id")" "$(escape "$began")" "$cpu" "$mem" "$pids" "$peak_cpu" "$peak_mem" "$(escape "$evidence_path")" "$status" "$(escape "$failures")"
}

for service in $MONITOR_SERVICES; do
    row=$(printf '%s\n' "$stats" | awk -F'|' -v s="$service" '$1 ~ ("^"s"([.]|$)"){print;exit}')
    [ -n "$row" ] || continue
    cpu=$(printf '%s' "$row" | cut -d'|' -f2 | tr -d '%'); mem=$(printf '%s' "$row" | cut -d'|' -f3 | tr -d '%'); pids=$(printf '%s' "$row" | cut -d'|' -f4)
    if ! number "$cpu" || ! number "$mem" || ! number "$pids"; then add_failure "malformed_$(safe_name "$service")"; continue; fi
    safe_service=$(safe_name "$service"); state="$MONITOR_STATE_DIR/incident-$safe_service.state"
    id=''; began=''; peak_cpu=0; peak_mem=0; recovery=0; samples=0
    if [ -r "$state" ]; then IFS='|' read -r id began peak_cpu peak_mem recovery samples < "$state" || true; fi
    if ! number "$peak_cpu" || ! number "$peak_mem" || ! number "$recovery" || ! number "$samples"; then rm -f "$state"; id=''; peak_cpu=0; peak_mem=0; recovery=0; samples=0; add_failure "corrupt_state_$safe_service"; fi
    high=0; { gt "$cpu" "$MONITOR_CPU_THRESHOLD" || [ "$cpu" = "$MONITOR_CPU_THRESHOLD" ]; } && high=1
    if [ -z "$id" ] && [ "$high" -eq 1 ]; then
        nonce=$(od -An -N4 -tu4 /dev/urandom 2>/dev/null | tr -d ' '); [ -n "$nonce" ] || nonce=$$
        id="$safe_service-$(date -u +%s)-$$-$nonce"; began="$ts"; peak_cpu="$cpu"; peak_mem="$mem"; recovery=0; samples=1; ev=$(evidence "$service" open "$id")
        printf '%s|%s|%s|%s|%s|%s\n' "$id" "$began" "$peak_cpu" "$peak_mem" "$recovery" "$samples" > "$state.$$" && mv "$state.$$" "$state"
        line "$(event performance_incident_open "$service" "$id" "$began" "$cpu" "$mem" "$pids" "$peak_cpu" "$peak_mem" "$ev")"; continue
    fi
    [ -n "$id" ] || continue
    samples=$((samples+1)); phase=active; ev=''; new_peak=0
    if gt "$cpu" "$peak_cpu"; then peak_cpu="$cpu"; new_peak=1; fi; if gt "$mem" "$peak_mem"; then peak_mem="$mem"; new_peak=1; fi
    [ "$high" -eq 1 ] && recovery=0 || recovery=$((recovery+1))
    if [ "$new_peak" -eq 1 ]; then phase=peak; ev=$(evidence "$service" peak "$id"); elif [ $((samples % MONITOR_ACTIVE_EVERY)) -eq 0 ]; then ev=$(evidence "$service" active "$id"); fi
    printf '%s|%s|%s|%s|%s|%s\n' "$id" "$began" "$peak_cpu" "$peak_mem" "$recovery" "$samples" > "$state.$$" && mv "$state.$$" "$state"
    line "$(event performance_incident_$phase "$service" "$id" "$began" "$cpu" "$mem" "$pids" "$peak_cpu" "$peak_mem" "$ev")"
    if [ "$recovery" -ge "$MONITOR_RECOVERY_SAMPLES" ]; then ev=$(evidence "$service" close "$id"); line "$(event performance_incident_close "$service" "$id" "$began" "$cpu" "$mem" "$pids" "$peak_cpu" "$peak_mem" "$ev")"; rm -f "$state"; fi
done

find "$MONITOR_LOG_DIR" -maxdepth 1 -type f -name 'resource-*.jsonl' -mtime +1 -exec gzip -f {} \; 2>/dev/null || add_failure retention_compress
find "$MONITOR_LOG_DIR" -maxdepth 1 -type f \( -name 'resource-*.jsonl.gz' -o -name 'incident-*.json' \) -mtime +"$MONITOR_RETENTION_DAYS" -delete 2>/dev/null || add_failure retention_delete
duration=$((($(date +%s)-started)*1000)); status=success; [ -n "$failures" ] && status=partial
if [ "$status" = success ]; then printf '%s\n' "$ts" > "$MONITOR_STATE_DIR/last-successful-collection.$$" && mv "$MONITOR_STATE_DIR/last-successful-collection.$$" "$MONITOR_STATE_DIR/last-successful-collection"; fi
line "{\"schema_version\":\"1\",\"type\":\"collector_health\",\"ts\":\"$(escape "$ts")\",\"collector_version\":\"$MONITOR_VERSION\",\"status\":\"$status\",\"duration_ms\":$duration,\"last_successful_collection\":\"$(escape "$last_successful")\",\"failed_sections\":\"$(escape "$failures")\"}"
