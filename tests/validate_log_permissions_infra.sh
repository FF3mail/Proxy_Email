#!/usr/bin/env bash
# PROMPT-78 — syntax validation for log permission infrastructure artifacts.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=== bash -n ==="
bash -n delta-transit-install.sh
bash -n mail-proxy-setup.sh
bash -n tests/validate_log_permissions_infra.sh

echo "=== systemd-analyze verify (unit template) ==="
if command -v systemd-analyze >/dev/null 2>&1; then
  systemd-analyze verify mail-proxy.service
else
  echo "SKIP: systemd-analyze not available"
fi

echo "=== logrotate -d ==="
if command -v logrotate >/dev/null 2>&1; then
  logrotate -d logrotate-mail-proxy >/dev/null
else
  echo "SKIP: logrotate not available"
fi

echo "=== python static tests ==="
python3 -m unittest tests.test_log_permissions_infra -v

echo "OK"
