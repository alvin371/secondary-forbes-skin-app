#!/usr/bin/env python3
"""Exercise report boundaries, deltas, rankings, mapping, locks, and gaps."""
import json
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def snapshot(ts, cpu):
    return {
        'type': 'resource_snapshot',
        'ts': ts,
        'containers': [{'name': 'mysql-8_mysql.1', 'cpu_percent': cpu}],
    }


with tempfile.TemporaryDirectory(prefix='incident-report-test-') as temp:
    root = Path(temp)
    resource = root / 'resource.jsonl'
    evidence = root / 'incident-mysql-open.json'
    evidence_close = root / 'incident-mysql-close.json'
    app = root / 'app.jsonl'
    rows = [
        snapshot('2025-12-31T23:59:00Z', 10),
        snapshot('2025-12-31T23:59:30Z', 11),
        snapshot('2026-01-01T00:00:00Z', 9),
        snapshot('2026-01-01T00:00:01Z', 80),
        snapshot('2026-01-01T00:00:02Z', 90),
        snapshot('2026-01-01T00:00:03Z', 100),
        {'type': 'collector_health', 'ts': '2026-01-01T00:00:04Z',
         'status': 'success', 'duration_ms': 4, 'failed_sections': ''},
        {'type': 'performance_incident_open', 'incident_id': 'i-1',
         'ts': '2026-01-01T00:00:01Z', 'started_at': '2026-01-01T00:00:01Z',
         'service_name': 'mysql-8_mysql', 'peak_cpu_percent': 80},
        {'type': 'performance_incident_active', 'incident_id': 'i-1',
         'ts': '2026-01-01T00:00:02Z', 'started_at': '2026-01-01T00:00:01Z',
         'service_name': 'mysql-8_mysql', 'peak_cpu_percent': 90},
        {'type': 'performance_incident_peak', 'incident_id': 'i-1',
         'ts': '2026-01-01T00:00:03Z', 'started_at': '2026-01-01T00:00:01Z',
         'service_name': 'mysql-8_mysql', 'peak_cpu_percent': 100},
        {'type': 'performance_incident_close', 'incident_id': 'i-1',
         'ts': '2026-01-01T00:00:04Z', 'started_at': '2026-01-01T00:00:01Z',
         'service_name': 'mysql-8_mysql', 'peak_cpu_percent': 100},
    ]
    resource.write_text('\n'.join(json.dumps(row) for row in rows) + '\n')
    evidence.write_text(json.dumps({
        'incident_id': 'i-1',
        'service_name': 'mysql-8_mysql',
        'phase': 'peak',
        'mysql_diagnostics': {
            'counters': {'snapshot': {'Questions': 1000, 'Threads_running': 2}},
            'digests': {'rows': [{
                'digest': 'abc', 'digest_text': 'SELECT * FROM `t` WHERE `id` = ?',
                'schema': 'app', 'execution_count': 100, 'total_time_ms': 1000,
                'avg_time_ms': 10, 'max_time_ms': 20, 'rows_examined': 10000,
                'rows_sent': 100, 'rows_affected': 0, 'lock_time_ms': 20,
                'tmp_disk_tables': 1,
            }]},
            'active_statements': {'status': 'ok', 'rows': [
                {'thread_id': '11', 'digest': 'waiter-digest'},
                {'thread_id': '22', 'digest': 'blocker-digest'},
            ]},
            'transactions': {'status': 'ok', 'rows': [{'trx_id': 'trx-1'}]},
            'lock_waits': {'status': 'ok', 'rows': [{
                'requesting_transaction_id': 'trx-1', 'blocking_transaction_id': 'trx-2',
                'requesting_thread_id': '11', 'blocking_thread_id': '22',
            }]},
        },
    }))
    evidence_close.write_text(json.dumps({
        'incident_id': 'i-1', 'service_name': 'mysql-8_mysql', 'phase': 'close',
        'captured_at': '2026-01-01T00:00:04Z',
        'mysql_diagnostics': {
            'counters': {'snapshot': {'Questions': 1020, 'Threads_running': 3}},
            'digests': {'rows': [{
                'digest': 'abc', 'digest_text': 'SELECT * FROM t WHERE id = ?',
                'schema': 'app', 'execution_count': 110, 'total_time_ms': 1100,
                'avg_time_ms': 10, 'max_time_ms': 25, 'rows_examined': 11000,
                'rows_sent': 110, 'rows_affected': 0, 'lock_time_ms': 22,
                'tmp_disk_tables': 2,
            }]},
            'active_statements': {'status': 'ok', 'rows': [
                {'thread_id': '11', 'digest': 'waiter-digest'},
                {'thread_id': '22', 'digest': 'blocker-digest'},
            ]},
            'transactions': {'status': 'ok', 'rows': [{'trx_id': 'trx-1'}]},
            'lock_waits': {'status': 'ok', 'rows': [{
                'requesting_transaction_id': 'trx-1', 'blocking_transaction_id': 'trx-2',
                'requesting_thread_id': '11', 'blocking_thread_id': '22',
            }]},
        },
    }))
    app.write_text(json.dumps({
        'type': 'request_performance', 'timestamp_utc': '2026-01-01T00:00:02Z',
        'application': 'forbes', 'route_or_command': 'cli:test',
        'db_total_ms': 100, 'db_query_count': 10,
        'top_query_fingerprints': [{
            'fingerprint': 'app-fp', 'normalized_query': 'select * from t where id = ?',
            'count': 10, 'total_ms': 100,
        }],
    }) + '\n')
    out = root / 'incident.json'
    md = root / 'incident.md'
    subprocess.run([
        'python3', str(ROOT / 'tools/monitoring/incident_report.py'),
        '--resource', str(resource), '--evidence-dir', str(root),
        '--app-log', str(app), '--incident-id', 'i-1',
        '--output-json', str(out), '--output-md', str(md),
    ], check=True)
    report = json.loads(out.read_text())
    assert report['schema'] == 'forbes.performance-incident/v1'
    assert report['incident_summary']['duration_seconds'] == 3.0
    assert report['incident_summary']['collector_health']['status'] == 'success'
    baseline = report['baseline_vs_incident']['baseline']
    incident = report['baseline_vs_incident']['incident']
    assert baseline['sample_count'] == 3 and baseline['p50_cpu_percent'] == 10
    assert incident['sample_count'] == 3 and incident['p95_cpu_percent'] == 99
    top = report['query_rankings']['ranked_by_total_time'][0]
    assert top['digest'] == 'abc' and top['execution_count'] == 10
    assert top['total_time_ms'] == 100 and top['rows_examined'] == 1000
    assert report['query_rankings']['rankings']['execution_count'][0]['execution_count'] == 10
    assert report['application_callers']['events'] == 1
    mapping = report['application_callers']['fingerprint_correlations'][0]
    assert mapping['route_or_job'] == 'cli:test'
    assert mapping['mysql_digest'] == 'abc' and mapping['confidence'] == 'confirmed'
    assert report['transactions_and_locks']['transactions']['status'] == 'ok'
    assert report['baseline_vs_incident']['mysql_counters']['delta']['Questions'] == 20
    relationship = report['transactions_and_locks']['relationships'][0]
    assert relationship['requester_digest'] == 'waiter-digest'
    assert relationship['blocker_digest'] == 'blocker-digest'
    assert [x['type'] for x in report['timeline']] == [
        'performance_incident_open', 'performance_incident_active',
        'performance_incident_peak', 'performance_incident_close',
    ]
    assert report['evidence_completeness']['status'] == 'complete'
    assert md.exists() and md.read_text().startswith('# Performance incident i-1')
print('incident_report_test: PASS baseline_delta_rankings_mapping_locks_timeline')
