#!/usr/bin/env bash
# Rebuild docs/DELTA_transit_admin_guide.pdf from docs/src/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 "${ROOT}/docs/build_admin_guide.py"
