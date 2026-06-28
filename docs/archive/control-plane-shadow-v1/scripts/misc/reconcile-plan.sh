#!/usr/bin/env bash
# reconcile-plan.sh — Safe correction plan generator (read-only).
#
# Outputs recommended PRs, commits, rollback steps, dependency ordering.
# Does NOT execute merge, deploy, git push, or Pi changes.
#
# Usage: ./scripts/reconcile-plan.sh [--pr <id>]

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

PR=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR="$2"; shift 2 ;;
    *) shift ;;
  esac
done

if ! command -v python3 >/dev/null 2>&1; then
  echo '{"error":"python3 required"}'
  exit 2
fi

export SHCL_FEED=1
JSON_OUT=1 SHCL_FEED=1 "$REPO_ROOT/scripts/release-check.sh" >/dev/null 2>&1 || true

OUT="$(python3 "$REPO_ROOT/scripts/lib/self_heal_analyze.py" reconcile)"
if [[ -n "$PR" ]]; then
  OUT="$(echo "$OUT" | python3 -c "
import json,sys
plan = json.load(sys.stdin)
plan['focus_pr'] = sys.argv[1]
print(json.dumps(plan, indent=2, ensure_ascii=False))
" "$PR")"
fi
echo "$OUT"
