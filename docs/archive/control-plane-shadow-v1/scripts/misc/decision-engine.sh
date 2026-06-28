#!/usr/bin/env bash
# decision-engine.sh — Decision Intelligence Layer (read-only brain).
#
# Does NOT merge, deploy, or modify code. Produces structured decision JSON only.
#
# Usage:
#   ./scripts/decision-engine.sh analyze --pr <id>
#
# Principle: "Execution enforces. Intelligence decides."

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

CMD="${1:-}"
shift || true

PR=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

usage() {
  echo "Usage: $0 analyze --pr <id>" >&2
  exit 2
}

cmd_analyze() {
  [[ -n "$PR" ]] || usage

  if ! command -v python3 >/dev/null 2>&1; then
    echo '{"error":"python3 required"}' >&2
    exit 2
  fi

  if ! command -v gh >/dev/null 2>&1; then
    python3 - <<PY
import json
print(json.dumps({
  "pr_id": "$PR",
  "risk_score": 1.0,
  "risk_level": "critical",
  "change_class": "UNKNOWN",
  "blast_radius": [],
  "recommended_strategy": "block",
  "rollback_complexity": "high",
  "confidence": 0.2,
  "reasoning": ["gh CLI not available — cannot fetch PR metadata"],
  "next_actions": ["Install gh and authenticate: gh auth login", "Re-run: ./scripts/decision-engine.sh analyze --pr $PR"]
}, indent=2))
PY
    exit 1
  fi

  OUT="$(python3 "$REPO_ROOT/scripts/lib/decision_analyze.py" "$PR")"
  echo "$OUT"
  mkdir -p "$REPO_ROOT/.cursor/.local/shcl"
  printf '%s' "$OUT" > "$REPO_ROOT/.cursor/.local/shcl/decision-pr-${PR}.json"
  # Cross-validated by: ./scripts/consistency-check.sh
}

case "$CMD" in
  analyze) cmd_analyze ;;
  *) usage ;;
esac
