#!/usr/bin/env sh
# Version-aware, bounded MySQL incident diagnostics. No credentials or literal
# SQL values are emitted. Configure a mode-0600 password file.
set -u
MYSQL_MONITOR_HOST=${MYSQL_MONITOR_HOST:-localhost}
MYSQL_MONITOR_PORT=${MYSQL_MONITOR_PORT:-3306}
MYSQL_MONITOR_USER=${MYSQL_MONITOR_USER:-forbes_monitor}
MYSQL_MONITOR_PASSWORD_FILE=${MYSQL_MONITOR_PASSWORD_FILE:-}
MYSQL_MONITOR_DATABASE=${MYSQL_MONITOR_DATABASE:-}
MYSQL_MONITOR_TIMEOUT_SECONDS=${MYSQL_MONITOR_TIMEOUT_SECONDS:-5}
MYSQL_MONITOR_MAX_ROWS=${MYSQL_MONITOR_MAX_ROWS:-100}
MYSQL_MONITOR_STATE_FILE=${MYSQL_MONITOR_STATE_FILE:-/var/lib/forbes-monitor/mysql-counters.json}
tmp=$(mktemp -d "${TMPDIR:-/tmp}/forbes-mysql-diag.XXXXXX") || exit 1
trap 'rm -rf "$tmp"' EXIT HUP INT TERM
failed=""
add_failure() { failed="${failed}${failed:+,}$1"; }
defaults=""
if [ -n "$MYSQL_MONITOR_PASSWORD_FILE" ] && [ -r "$MYSQL_MONITOR_PASSWORD_FILE" ]; then
  umask 077
  defaults="$tmp/client.cnf"
  { printf '%s\n' '[client]'; printf 'password=%s\n' "$(sed -n '1p' "$MYSQL_MONITOR_PASSWORD_FILE")"; } > "$defaults"
fi
mysql_query() {
  name=$1; sql=$2; out="$tmp/$name.tsv"; err="$tmp/$name.err"
  args="--batch --raw --skip-column-names --connect-timeout=$MYSQL_MONITOR_TIMEOUT_SECONDS -h $MYSQL_MONITOR_HOST -P $MYSQL_MONITOR_PORT -u $MYSQL_MONITOR_USER"
  [ -n "$MYSQL_MONITOR_DATABASE" ] && args="$args $MYSQL_MONITOR_DATABASE"
  [ -n "$defaults" ] && args="--defaults-extra-file=$defaults $args"
  if command -v timeout >/dev/null 2>&1; then
    timeout "$MYSQL_MONITOR_TIMEOUT_SECONDS" sh -c "mysql $args -e \"\$sql\"" >"$out" 2>"$err"
  else
    mysql $args -e "$sql" >"$out" 2>"$err"
  fi
  rc=$?; [ "$rc" -eq 0 ] || add_failure "$name"; [ -r "$out" ] || : >"$out"
}
mysql_query meta "SELECT 'version',VERSION() UNION ALL SELECT 'version_comment',@@version_comment UNION ALL SELECT 'performance_schema',@@performance_schema UNION ALL SELECT 'time_zone',@@time_zone"
mysql_query variables "SHOW VARIABLES WHERE Variable_name IN ('slow_query_log','long_query_time','log_output','log_timestamps','min_examined_row_limit','max_connections','innodb_buffer_pool_size')"
mysql_query status "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Questions','Queries','Com_commit','Com_rollback','Rows_read','Rows_sent','Rows_inserted','Rows_updated','Rows_deleted','Created_tmp_tables','Created_tmp_disk_tables','Sort_rows','Sort_scan','Sort_range','Select_scan','Select_full_join','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Innodb_buffer_pool_pages_dirty','Innodb_buffer_pool_pages_flushed','Innodb_log_writes','Innodb_os_log_written','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_current_waits','Innodb_deadlocks','Aborted_connects','Aborted_clients')"
mysql_query processes "SELECT COALESCE(USER,''),SUBSTRING_INDEX(HOST,':',1),COMMAND,COALESCE(STATE,''),COUNT(*) FROM information_schema.PROCESSLIST GROUP BY USER,SUBSTRING_INDEX(HOST,':',1),COMMAND,STATE ORDER BY COUNT(*) DESC LIMIT $MYSQL_MONITOR_MAX_ROWS"
mysql_query active_statements "SELECT THREAD_ID,EVENT_ID,DIGEST,DIGEST_TEXT,ROUND(TIMER_WAIT/1000000000,3),ROWS_EXAMINED,ROWS_SENT,ROWS_AFFECTED,NO_INDEX_USED,NO_GOOD_INDEX_USED,CREATED_TMP_TABLES,CREATED_TMP_DISK_TABLES,SELECT_SCAN,SELECT_FULL_JOIN,SORT_MERGE_PASSES FROM performance_schema.events_statements_current WHERE END_EVENT_ID IS NULL ORDER BY TIMER_WAIT DESC LIMIT $MYSQL_MONITOR_MAX_ROWS"
mysql_query digests "SELECT SCHEMA_NAME,DIGEST,DIGEST_TEXT,COUNT_STAR,ROUND(SUM_TIMER_WAIT/1000000000,3),ROUND(AVG_TIMER_WAIT/1000000000,3),ROUND(MAX_TIMER_WAIT/1000000000,3),ROUND(SUM_LOCK_TIME/1000000000,3),SUM_ROWS_EXAMINED,SUM_ROWS_SENT,SUM_ROWS_AFFECTED,SUM_CREATED_TMP_TABLES,SUM_CREATED_TMP_DISK_TABLES,SUM_SELECT_SCAN,SUM_SELECT_FULL_JOIN,SUM_SORT_MERGE_PASSES,SUM_ERRORS,SUM_WARNINGS FROM performance_schema.events_statements_summary_by_digest ORDER BY SUM_TIMER_WAIT DESC LIMIT $MYSQL_MONITOR_MAX_ROWS"
mysql_query transactions "SELECT trx_id,trx_state,trx_started,trx_wait_started,trx_mysql_thread_id,trx_rows_locked,trx_rows_modified FROM information_schema.innodb_trx ORDER BY trx_started LIMIT $MYSQL_MONITOR_MAX_ROWS"
mysql_query lock_waits "SELECT REQUESTING_ENGINE_TRANSACTION_ID,BLOCKING_ENGINE_TRANSACTION_ID,REQUESTING_THREAD_ID,BLOCKING_THREAD_ID FROM performance_schema.data_lock_waits LIMIT $MYSQL_MONITOR_MAX_ROWS"
mysql_query innodb_status "SHOW ENGINE INNODB STATUS"
if command -v python3 >/dev/null 2>&1; then
python3 - "$tmp" "$MYSQL_MONITOR_STATE_FILE" "$failed" <<'PY'
import json, os, sys, time
from pathlib import Path
tmp, state_path, failed = sys.argv[1:]
def rows(name):
    p=Path(tmp,name+'.tsv')
    if name in failed.split(',') or not p.exists(): return None
    return [x.rstrip('\n').split('\t') for x in p.read_text(errors='replace').splitlines() if x.strip()]
def section(name, headers=None):
    data=rows(name)
    if data is None: return {'status':'unavailable'}
    return {'status':'ok','rows':[dict(zip(headers,x)) for x in data]} if headers else {'status':'ok','rows':data}
meta={x[0]:x[1] for x in (rows('meta') or []) if len(x)>1}
variables={x[0]:x[1] for x in (rows('variables') or []) if len(x)>1}
raw={x[0]:x[1] for x in (rows('status') or []) if len(x)>1}
current={}
for k,v in raw.items():
    try: current[k]=int(v)
    except ValueError: pass
previous={}
try: previous=json.loads(Path(state_path).read_text())
except (OSError,ValueError): pass
delta={k:(v-previous[k] if isinstance(previous.get(k),int) and v>=previous[k] else None) for k,v in current.items()}
Path(state_path).parent.mkdir(parents=True,exist_ok=True)
tmp_state=str(state_path)+'.tmp'
Path(tmp_state).write_text(json.dumps(current,separators=(',',':')))
os.replace(tmp_state,state_path)
out={'schema_version':'1','type':'mysql_diagnostics','captured_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
'mysql':{'version':meta.get('version'),'version_comment':meta.get('version_comment'),'performance_schema':meta.get('performance_schema'),'time_zone':meta.get('time_zone')},
'configuration':variables,'counters':{'snapshot':current,'delta':delta,'previous_available':bool(previous)},
'processes':section('processes',['user','host','command','state','connections']),
'active_statements':section('active_statements',['thread_id','event_id','digest','digest_text','time_ms','rows_examined','rows_sent','rows_affected','no_index_used','no_good_index_used','tmp_tables','tmp_disk_tables','select_scan','select_full_join','sort_merge_passes']),
'digests':section('digests',['schema','digest','digest_text','execution_count','total_time_ms','avg_time_ms','max_time_ms','lock_time_ms','rows_examined','rows_sent','rows_affected','tmp_tables','tmp_disk_tables','select_scan','select_full_join','sort_merge_passes','errors','warnings']),
'transactions':section('transactions',['trx_id','state','started','wait_started','thread_id','rows_locked','rows_modified']),
'lock_waits':section('lock_waits',['requesting_transaction_id','blocking_transaction_id','requesting_thread_id','blocking_thread_id']),
'innodb_status':section('innodb_status'),'collection':{'status':'success' if not failed else 'partial','failed_sections':[x for x in failed.split(',') if x]}}
print(json.dumps(out,separators=(',',':'),ensure_ascii=False))
PY
else
printf '%s\n' '{"schema_version":"1","type":"mysql_diagnostics","collection":{"status":"failed","failed_sections":["python3_missing"]}}'
fi
