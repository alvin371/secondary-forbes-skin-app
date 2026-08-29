#!/usr/bin/env python3
"""Bounded, evidence-first incident report generator."""
import argparse, gzip, json
from collections import defaultdict, Counter
from datetime import datetime, timezone, timedelta
from pathlib import Path
SCHEMA="forbes.performance-incident/v1"

def read_lines(path):
    opener=gzip.open if str(path).endswith(".gz") else open
    with opener(path,"rt",encoding="utf-8",errors="replace") as f:
        for line in f:
            if " PERF " in line:
                line = line.split(" PERF ", 1)[1]
            try:
                x=json.loads(line)
                if isinstance(x,dict): yield x
            except (ValueError,TypeError): pass

def dt(v):
    try: return datetime.fromisoformat(str(v).replace("Z","+00:00")).astimezone(timezone.utc) if v else None
    except ValueError: return None
def n(v):
    try: return float(v)
    except (TypeError,ValueError): return None
def canonical_sql(v):
    if not isinstance(v,str): return None
    v=' '.join(v.replace('`','').upper().split())
    return v[:1024]
def percentile(values, p):
    values=sorted(x for x in values if x is not None)
    if not values: return None
    if len(values)==1: return values[0]
    index=(len(values)-1)*p; lower=int(index); upper=min(lower+1,len(values)-1)
    return round(values[lower]+(values[upper]-values[lower])*(index-lower),3)
def report(resources,events,evidence,apps,incident_id,health=None):
    health=health or []
    events=sorted(events,key=lambda x:dt(x.get("ts")) or datetime.max.replace(tzinfo=timezone.utc))
    opened=next((x for x in events if x.get("type")=="performance_incident_open"),None)
    closed=next((x for x in events[::-1] if x.get("type")=="performance_incident_close"),None)
    start=dt((opened or {}).get("started_at") or (opened or {}).get("ts"))
    end=dt((closed or {}).get("ts")) if closed else None
    peak=max(events,key=lambda x:n(x.get("peak_cpu_percent")) or -1,default={})
    mysql_events=[x for x in events if "mysql" in str(x.get("service_name",""))]
    mysql_peak=max((n(x.get("peak_cpu_percent")) for x in mysql_events),default=None)
    incident_id=incident_id or (opened or {}).get("incident_id") or "unknown"
    inside=[x for x in resources if (t:=dt(x.get("ts"))) and (not start or t>=start) and (not end or t<=end)]
    baseline_start=start-timedelta(minutes=10) if start else None
    baseline=[x for x in resources if (t:=dt(x.get("ts"))) and baseline_start and baseline_start<=t<start]
    names={}
    for x in inside:
        for c in x.get("containers",[]):
            names.setdefault(c.get("name","unknown"),[]).append(c)
    peaks=[]
    for name,rows in names.items():
        p=max(rows,key=lambda c:n(c.get("cpu_percent")) or -1)
        peaks.append({"container":name,"peak_cpu_percent":n(p.get("cpu_percent")),"peak_memory_percent":n(p.get("memory_percent")),"sample_count":len(rows)})
    def averages(rows):
        grouped=defaultdict(list)
        for row in rows:
            for c in row.get("containers",[]):
                value=n(c.get("cpu_percent"))
                if value is not None: grouped[c.get("name","unknown")].append(value)
        return [{"container":k,"avg_cpu_percent":round(sum(v)/len(v),3),"sample_count":len(v)} for k,v in grouped.items()]
    def sample_stats(rows):
        values=[]
        for row in rows:
            for c in row.get("containers",[]):
                if n(c.get("cpu_percent")) is not None: values.append(n(c.get("cpu_percent")))
        return {"sample_count":len(values),"p50_cpu_percent":percentile(values,.5),"p95_cpu_percent":percentile(values,.95),"sufficient":len(values)>=3}
    digest_points=defaultdict(list); counter_points=[]; lock_evidence={"transactions":None,"lock_waits":None}; active_rows=[]
    for ev in evidence:
        raw=ev.get("mysql_diagnostics")
        if isinstance(raw,str):
            try: raw=json.loads(raw)
            except ValueError: raw=None
        if isinstance(raw,dict):
            captured=dt(ev.get("captured_at")) or datetime.min.replace(tzinfo=timezone.utc)
            snapshot=(raw.get("counters") or {}).get("snapshot") if isinstance(raw.get("counters"),dict) else None
            if isinstance(snapshot,dict): counter_points.append((captured,snapshot))
            for row in raw.get("digests",{}).get("rows",[]) or []:
                if row.get("digest"):
                    digest_points[row["digest"]].append((captured, row))
            for key in lock_evidence:
                if key in raw and isinstance(raw[key],dict): lock_evidence[key]=raw[key]
            active_section=raw.get("active_statements")
            if isinstance(active_section,dict): active_rows += active_section.get("rows",[]) or []
    cumulative=("execution_count","total_time_ms","lock_time_ms","rows_examined",
                "rows_sent","rows_affected","tmp_tables","tmp_disk_tables",
                "select_scan","select_full_join","sort_merge_passes","errors","warnings")
    digests=[]
    for digest, points in digest_points.items():
        points.sort(key=lambda item:item[0])
        first=points[0][1]; last=points[-1][1]
        item=dict(last)
        item["snapshot_count"]=len(points)
        item["window_delta_available"]=len(points) >= 2
        if len(points) >= 2:
            for key in cumulative:
                before=n(first.get(key)); after=n(last.get(key))
                item[key]=round(after-before,3) if before is not None and after is not None and after >= before else None
            total=n(item.get("total_time_ms")); count=n(item.get("execution_count"))
            item["avg_time_ms"]=round(total/count,3) if total is not None and count else None
        for key in ("execution_count","total_time_ms","avg_time_ms","max_time_ms","lock_time_ms","rows_examined","rows_sent","rows_affected","tmp_tables","tmp_disk_tables","select_scan","select_full_join","sort_merge_passes","errors","warnings"):
            if key in item and item[key] is not None: item[key]=n(item[key])
        digests.append(item)
    digests=sorted(digests,key=lambda x:x.get("total_time_ms") or 0,reverse=True)[:50]
    measured_db_time=sum(x.get("total_time_ms") or 0 for x in digests)
    for x in digests:
        x["percentage_of_incident_db_time"]=round((x.get("total_time_ms") or 0)*100/measured_db_time,3) if measured_db_time else None
    relationships=[]
    waits=(lock_evidence.get("lock_waits") or {}).get("rows",[]) if isinstance(lock_evidence.get("lock_waits"),dict) else []
    for wait in waits:
        requested=str(wait.get("requesting_thread_id") or "")
        blocked=str(wait.get("blocking_thread_id") or "")
        requester=next((x for x in active_rows if str(x.get("thread_id")) == requested),None)
        blocker=next((x for x in active_rows if str(x.get("thread_id")) == blocked),None)
        relationships.append({"requesting_transaction_id":wait.get("requesting_transaction_id"),"blocking_transaction_id":wait.get("blocking_transaction_id"),"requester_thread_id":wait.get("requesting_thread_id"),"blocker_thread_id":wait.get("blocking_thread_id"),"requester_digest":(requester or {}).get("digest"),"blocker_digest":(blocker or {}).get("digest")})
    lock_evidence["relationships"]=relationships
    counter_points.sort(key=lambda item:item[0])
    counter_delta={}
    if len(counter_points) >= 2:
        before=counter_points[0][1]; after=counter_points[-1][1]
        for key, value in after.items():
            old=n(before.get(key)); new=n(value)
            counter_delta[key]=round(new-old,3) if old is not None and new is not None and new >= old else None
    mysql_counter_evidence={"status":"observed" if len(counter_points)>=2 else "unavailable","snapshot_count":len(counter_points),"delta":counter_delta}
    rankings={"total_database_time":digests[:20]}
    for label,key in (("execution_count","execution_count"),("average_latency","avg_time_ms"),("maximum_latency","max_time_ms"),("rows_examined","rows_examined"),("lock_time","lock_time_ms"),("disk_temporary_tables","tmp_disk_tables")):
        rankings[label]=sorted(digests,key=lambda x:x.get(key) or 0,reverse=True)[:20]
    selected=[x for x in apps if (t:=dt(x.get("timestamp_utc"))) and (not start or t>=start) and (not end or t<=end)]
    callers=defaultdict(lambda:{"events":0,"db_total_ms":0.0,"db_query_count":0,"items_processed":0,"items_succeeded":0,"failures":0,"attempts":0,"retries":0,"concurrency":None,"transaction_count":0,"fingerprints":Counter(),"fingerprint_details":{}})
    for x in selected:
        key=x.get("route_or_command") or x.get("job_or_command_name") or "unknown"; y=callers[key]
        y["events"]+=1; y["db_total_ms"]+=n(x.get("db_total_ms")) or 0; y["db_query_count"]+=int(n(x.get("db_query_count")) or 0)
        y["items_processed"]+=int(n(x.get("items_processed")) or 0)
        y["items_succeeded"]+=int(n(x.get("items_succeeded")) or 0)
        y["failures"]+=int(n(x.get("items_failed")) or 0)
        y["attempts"]+=int(n(x.get("attempt_number")) or 1)
        y["retries"]+=int(n(x.get("retry_count")) or 0)
        concurrency=n(x.get("concurrency"))
        if concurrency is not None: y["concurrency"]=max(y["concurrency"] or 0, concurrency)
        y["transaction_count"]+=int(n(x.get("transaction_count")) or 0)
        for fp in x.get("top_query_fingerprints") or []:
            if fp.get("fingerprint"):
                y["fingerprints"][fp["fingerprint"]]+=int(n(fp.get("count")) or 0)
                y["fingerprint_details"][fp["fingerprint"]]=fp
    caller_rows=[]
    for key,y in callers.items():
        completed=y["items_succeeded"]+y["failures"]
        caller_rows.append({k:v for k,v in y.items() if k not in ("fingerprints","fingerprint_details")} | {
            "route_or_job":key,
            "queries_per_item":round(y["db_query_count"]/y["items_processed"],3) if y["items_processed"] else None,
            "success_rate":round(y["items_succeeded"]/completed,3) if completed else None,
            "attempts_and_retries":{"attempts":y["attempts"],"retries":y["retries"]},
            "top_fingerprints":y["fingerprints"].most_common(10),
        })
    digest_by_sql={canonical_sql(x.get("digest_text")):x for x in digests if canonical_sql(x.get("digest_text"))}
    correlations=[]
    for key,y in callers.items():
        for fingerprint, count in y["fingerprints"].items():
            app_fp=y["fingerprint_details"].get(fingerprint,{})
            sql=canonical_sql(app_fp.get("normalized_query"))
            match=digest_by_sql.get(sql) if sql else None
            mysql_count=match.get("execution_count") if match else None
            correlations.append({"route_or_job":key,"application_fingerprint":fingerprint,"application_count":count,"mysql_execution_count":mysql_count,"count_reconciliation":"equal" if match and mysql_count == count else ("different_or_sampled" if match else "unavailable"),"normalized_query":sql,"mysql_digest":match.get("digest") if match else None,"mapping":"normalized_query_exact" if match else "unavailable","confidence":"confirmed" if match else "unknown"})
    missing=[]
    if not events: missing.append("incident lifecycle events")
    if not digests: missing.append("MySQL digest evidence")
    elif not all(x.get("window_delta_available") for x in digests): missing.append("incident-window digest deltas")
    if mysql_counter_evidence["status"] != "observed": missing.append("incident-window MySQL counter deltas")
    if not selected: missing.append("application caller evidence")
    if not any(x["confidence"]=="confirmed" for x in correlations): missing.append("application-to-MySQL fingerprint mapping")
    if lock_evidence["transactions"] is None: missing.append("transaction evidence")
    if lock_evidence["lock_waits"] is None: missing.append("blocker/waiter evidence")
    evidence_status="complete" if not missing else "partial"
    collector_health=health[-1] if health else {"status":"unavailable"}
    if not health: missing.append("collector health")
    elif collector_health.get("status") != "success": missing.append("collector health partial/failure")
    evidence_status="complete" if not missing else "partial"
    return {"schema":SCHEMA,"incident_summary":{"incident_id":incident_id,"start_utc":start.isoformat().replace("+00:00","Z") if start else None,"peak_utc":dt(peak.get("ts")).isoformat().replace("+00:00","Z") if peak.get("ts") else None,"end_utc":end.isoformat().replace("+00:00","Z") if end else None,"duration_seconds":(end-start).total_seconds() if start and end else None,"threshold_triggered":bool(opened),"peak_host_cpu":None,"peak_mysql_cpu":mysql_peak,"peak_cpu_percent":n(peak.get("peak_cpu_percent")),"affected_containers":sorted({x.get("service_name") for x in events if x.get("service_name")}),"collector_health":collector_health},"baseline_vs_incident":{"incident_samples":len(inside),"baseline_samples":len(baseline),"baseline":sample_stats(baseline),"incident":sample_stats(inside),"mysql_counters":mysql_counter_evidence,"container_peaks":sorted(peaks,key=lambda x:x["peak_cpu_percent"] or -1,reverse=True),"incident_container_averages":averages(inside),"baseline_container_averages":averages(baseline),"evidence":"observed" if inside else "unavailable"},"query_rankings":{"ranked_by_total_time":digests,"rankings":rankings,"evidence":"observed" if digests else "unavailable"},"application_callers":{"events":len(selected),"callers":sorted(caller_rows,key=lambda x:x["db_total_ms"],reverse=True),"fingerprint_correlations":correlations,"evidence":"observed" if selected else "unavailable"},"transactions_and_locks":lock_evidence,"timeline":events[:200],"diagnosis":{"observations":["Resource, lifecycle, query, and caller values are kept as separate evidence sources."],"hypotheses":[{"confidence":"moderate" if digests and selected and any(x["confidence"]=="confirmed" for x in correlations) else "weak","statement":"Database saturation is observed; root cause is unconfirmed until query digest and caller windows overlap.","missing_evidence":missing}]},"evidence_completeness":{"status":evidence_status,"missing":missing}}

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--resource",action="append",default=[]); p.add_argument("--evidence-dir",type=Path,default=Path("."))
    p.add_argument("--app-log",action="append",default=[]); p.add_argument("--incident-id"); p.add_argument("--output-json",required=True); p.add_argument("--output-md")
    a=p.parse_args()
    resources=[]; events=[]; health=[]; apps=[]
    for f in a.resource:
        for x in read_lines(f):
            (resources if x.get("type")=="resource_snapshot" else events if x.get("type","").startswith("performance_incident") else health if x.get("type")=="collector_health" else []).append(x)
    evidence=[]
    for f in a.evidence_dir.glob("incident-*.json"):
        try:
            item=json.loads(f.read_text())
            if a.incident_id and item.get("incident_id") not in (None,a.incident_id): continue
            evidence.append(item)
        except (OSError,ValueError): pass
    for f in a.app_log: apps += list(read_lines(f))
    out=report(resources,[x for x in events if not a.incident_id or x.get("incident_id")==a.incident_id],evidence,apps,a.incident_id,health)
    Path(a.output_json).write_text(json.dumps(out,indent=2)+"\n")
    if a.output_md:
        s=out["incident_summary"]; q=out["query_rankings"]; b=out["baseline_vs_incident"]; callers=out["application_callers"]; locks=out["transactions_and_locks"]
        lines=["# Performance incident "+str(s["incident_id"]),"","Start UTC: "+str(s["start_utc"]),"Peak UTC: "+str(s["peak_utc"]),"End UTC: "+str(s["end_utc"]),"Evidence: "+out["evidence_completeness"]["status"],"Collector health: "+str(s.get("collector_health",{}).get("status")),"","## Baseline versus incident","",f"- Baseline samples: {b['baseline']['sample_count']} p50={b['baseline']['p50_cpu_percent']} p95={b['baseline']['p95_cpu_percent']}",f"- Incident samples: {b['incident']['sample_count']} p50={b['incident']['p50_cpu_percent']} p95={b['incident']['p95_cpu_percent']}",f"- MySQL counter delta status: {b['mysql_counters']['status']}","","## Query fingerprints",""]
        lines += ["- "+str(x.get("digest"))+" count="+str(x.get("execution_count"))+" total_ms="+str(x.get("total_time_ms")) for x in q["ranked_by_total_time"][:20]]
        lines += ["","## Application callers",""]+["- "+str(x.get("route_or_job"))+" events="+str(x.get("events"))+" db_ms="+str(x.get("db_total_ms"))+" queries_per_item="+str(x.get("queries_per_item")) for x in callers["callers"][:20]]
        lines += ["","## Transactions and locks",""]+["- blocker="+str(x.get("blocker_thread_id"))+" waiter="+str(x.get("requester_thread_id"))+" blocker_digest="+str(x.get("blocker_digest"))+" waiter_digest="+str(x.get("requester_digest")) for x in locks.get("relationships",[])[:20]]
        lines += ["","## Diagnosis",""]+["- "+str(h.get("confidence"))+": "+str(h.get("statement")) for h in out["diagnosis"]["hypotheses"]]
        lines += ["","## Missing evidence"]+["- "+x for x in out["evidence_completeness"]["missing"]]
        Path(a.output_md).write_text("\n".join(lines)+"\n")
if __name__=="__main__": main()
