#!/usr/bin/env bash
# self-heal-engine.sh — Self-Healing + Consistency Layer (read-only).
#
# Usage:
#   ./scripts/self-heal-engine.sh analyze
#   ./scripts/self-heal-engine.sh analyze --pr <id>   # include focused PR in report
#
# Principle: "Detection is not enough. The system must converge."
# Does NOT auto-deploy, auto-merge, or modify application code.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

CMD="${1:-analyze}"
shift || true

PR=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

if ! command -v python3 >/dev/null 2>&1; then
  echo '{"status":"CRITICAL_CONFLICT","severity":"critical","error":"python3 required"}'
  exit 2
fi

# Refresh drift feed for SHCL
export SHCL_FEED=1
JSON_OUT=1 SHCL_FEED=1 "$REPO_ROOT/scripts/release-check.sh" >/dev/null 2>&1 || true

PY="$REPO_ROOT/scripts/lib/self_heal_analyze.py"

case "$CMD" in
  analyze)
    OUT="$(python3 "$PY" analyze)"
    if [[ -n "$PR" ]]; then
      OUT="$(python3 -c "
import json, os, subprocess, sys
base = json.loads(sys.argv[1])
pr = sys.argv[2]
repo = os.environ['REPO_ROOT']
for script, args in [
    ('sop-enforce.sh', ['--pr', pr]),
    ('decision-engine.sh', ['analyze', '--pr', pr]),
]:
    p = os.path.join(repo, 'scripts', script)
    if os.path.isfile(p):
        r = subprocess.run([p]+args, capture_output=True, text=True, cwd=repo)
        import re
        m = re.search(r'\{[\s\S]*\}\s*$', r.stdout or '')
        if m:
            base.setdefault('pr_focus', {})[script] = json.loads(m.group(0))
print(json.dumps(base, indent=2, ensure_ascii=False))
" "$OUT" "$PR")"
    fi
    echo "$OUT"
    STATUS="$(echo "$OUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("status","DRIFT_DETECTED"))')"
    if [[ "$STATUS" == "SYNCED" ]]; then
      exit 0
    fi
    exit 1
    ;;
  *)
    echo "Usage: $0 analyze [--pr N]" >&2
    exit 2
    ;;
esac
