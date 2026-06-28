#!/usr/bin/env bash
# sop-checklist.sh — Deterministic SOP checklist per PR or local commit.
#
# Usage:
#   ./scripts/sop-checklist.sh --pr <id>
#   ./scripts/sop-checklist.sh --pr <id> --run <step>
#   ./scripts/sop-checklist.sh --commit
#
# Steps for --run: decision_engine | decision_simulate | release_exec_validate

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

PR=""
MODE=""
RUN_STEP=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) MODE="pr"; PR="$2"; shift 2 ;;
    --commit) MODE="commit"; shift ;;
    --run) RUN_STEP="$2"; shift 2 ;;
    *) echo "Unknown: $1" >&2; exit 2 ;;
  esac
done

[[ -n "$MODE" ]] || { echo "Usage: $0 --pr N | --commit [--run step]" >&2; exit 2; }

PY="$REPO_ROOT/scripts/lib/sop_enforce.py"

if [[ "$MODE" == "pr" ]]; then
  [[ -n "$PR" ]] || exit 2
  if [[ -n "$RUN_STEP" ]]; then
    case "$RUN_STEP" in
      decision_engine|decision_simulate|release_exec_validate)
        python3 "$PY" stamp-run "$PR" "$RUN_STEP"
        exit $?
        ;;
      *)
        echo "Unknown step: $RUN_STEP" >&2
        exit 2
        ;;
    esac
  fi
  python3 "$PY" checklist "$PR"
  exit 0
fi

if [[ "$MODE" == "commit" ]]; then
  BRANCH="$(git branch --show-current 2>/dev/null || echo "")"
  PATHS="$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null || true)"
  echo "=== SOP Commit Checklist ==="
  echo "Branch: $BRANCH"
  echo ""
  echo "[ ] Not on main/master"
  echo "[ ] Branch matches feat/fix/chore/hotfix/*"
  echo "[ ] RELEASE_CLASS set if deployable paths staged"
  echo "[ ] AI handoff passes sop-enforce --ai (if ALLTRUE_AI_COMMIT=1)"
  echo ""
  ./scripts/sop-enforce.sh --commit 2>/dev/null || true
  exit 0
fi
