#!/usr/bin/env python3
"""Synthetic collector overhead check; not a production benchmark."""
import os
import subprocess
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='monitor-overhead-') as temp:
    root = Path(temp)
    env = os.environ.copy()
    env.update({
        'MONITOR_LOG_DIR': str(root / 'log'),
        'MONITOR_STATE_DIR': str(root / 'state'),
        'MONITOR_SERVICES': 'mysql-8_mysql',
        'MONITOR_STATS_COMMAND': "printf 'mysql-8_mysql|10%|1%|1|0B / 0B|0B / 0B\\n'",
        'MONITOR_DIAGNOSTICS_COMMAND': '',
        'MONITOR_RETENTION_DAYS': '1',
    })
    elapsed = []
    for _ in range(10):
        started = time.perf_counter()
        subprocess.run([str(ROOT / 'tools/monitoring/resource_snapshot.sh')],
                       env=env, check=True, stdout=subprocess.DEVNULL)
        elapsed.append((time.perf_counter() - started) * 1000)
    files = list((root / 'log').glob('*'))
    size = sum(path.stat().st_size for path in files if path.is_file())
    print('overhead_test: PASS environment=synthetic iterations=10 '
          f'avg_collector_ms={sum(elapsed)/len(elapsed):.3f} '
          f'max_collector_ms={max(elapsed):.3f} log_bytes={size}')
