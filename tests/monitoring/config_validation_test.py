#!/usr/bin/env python3
"""Validate non-secret example configuration and systemd templates."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
env = (ROOT / '.env.example').read_text()
keys = re.findall(r'^([A-Z][A-Z0-9_]+)=', env, re.M)
assert len(keys) == len(set(keys)), 'duplicate .env.example keys'
assert 'MYSQL_MONITOR_PASSWORD=' not in env
assert 'MYSQL_MONITOR_PASSWORD_FILE=' in env
unit = (ROOT / 'deploy/monitoring/forbes-resource-monitor.service').read_text()
assert 'EnvironmentFile=-%h/.config/forbes-monitor.env' in unit
assert 'resource_snapshot.sh' in unit
print('config_validation_test: PASS unique_keys_nonsecret_templates')
