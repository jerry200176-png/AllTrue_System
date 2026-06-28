#!/usr/bin/env bash
# consistency-check.sh — Cross-layer alignment validator (read-only).
#
# Validates:
#   SOP ↔ Decision engine
#   Decision ↔ Execution
#   Execution ↔ Production
#   Production ↔ Git
#
# Usage: ./scripts/consistency-check.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

if ! command -v python3 >/dev/null 2>&1; then
  echo '{"result":"FAIL","error":"python3 required"}'
  exit 2
fi

export SHCL_FEED=1
JSON_OUT=1 SHCL_FEED=1 "$REPO_ROOT/scripts/release-check.sh" >/dev/null 2>&1 || true

python3 "$REPO_ROOT/scripts/lib/self_heal_analyze.py" consistency
