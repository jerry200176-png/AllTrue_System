#!/usr/bin/env bash
# sop-enforce.sh — SOP Enforcement Engine (runtime gate).
#
# Converts docs/current-engineering-sop.md into executable validation.
# Runs BEFORE decision-engine and execution layer in the enforcement stack.
#
# Usage:
#   ./scripts/sop-enforce.sh --pr <id> [--action merge|deploy|validate]
#   ./scripts/sop-enforce.sh --commit
#   ./scripts/sop-enforce.sh --ai [--file path] [--stdin]
#   ./scripts/sop-enforce.sh --pr <id> --exec-json-file path/to/release-exec.json
#
# Principle: "SOP is law. Enforcement is reality."
# Does NOT merge, deploy, or modify application code.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

PR=""
ACTION=""
MODE=""
AI_FILE=""
EXEC_JSON_FILE=""
AUTO_RUN=0

usage() {
  echo "Usage:" >&2
  echo "  $0 --pr <id> [--action merge|deploy|validate] [--exec-json-file F] [--auto-run]" >&2
  echo "  $0 --commit" >&2
  echo "  $0 --ai [--file F | --stdin]" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) MODE="pr"; PR="$2"; shift 2 ;;
    --commit) MODE="commit"; shift ;;
    --ai) MODE="ai"; shift ;;
    --action) ACTION="$2"; shift 2 ;;
    --exec-json-file) EXEC_JSON_FILE="$2"; shift 2 ;;
    --file) AI_FILE="$2"; shift 2 ;;
    --stdin) AI_STDIN=1; shift ;;
    --auto-run) AUTO_RUN=1; shift ;;
    *) usage ;;
  esac
done

[[ -n "$MODE" ]] || usage

if ! command -v python3 >/dev/null 2>&1; then
  echo '{"status":"fail","violations":["python3 required"],"sop_stage":"missing","allowed_next_step":[]}'
  exit 2
fi

PY="$REPO_ROOT/scripts/lib/sop_enforce.py"

sop_override_ok() {
  [[ "${ALLTRUE_SOP_OVERRIDE:-}" == "yes" || "${ALLTRUE_EXEC_APPROVED:-}" == "yes" ]]
}

run_and_exit() {
  local code=$1
  if [[ "$code" -ne 0 ]]; then
    mkdir -p "$REPO_ROOT/.cursor/.local/shcl"
    python3 - <<PY 2>/dev/null || true
import json, datetime, pathlib
p = pathlib.Path("$REPO_ROOT/.cursor/.local/shcl/healing-triggers.jsonl")
row = {
    "ts": datetime.datetime.utcnow().isoformat() + "Z",
    "source": "sop-enforce.sh",
    "mode": "${MODE:-unknown}",
    "pr": "${PR:-}",
    "action": "${ACTION:-}",
    "status": "fail",
}
p.parent.mkdir(parents=True, exist_ok=True)
with p.open("a", encoding="utf-8") as f:
    f.write(json.dumps(row, ensure_ascii=False) + "\n")
PY
  fi
  if [[ "$code" -ne 0 ]] && sop_override_ok; then
    echo '{"status":"pass","violations":[],"sop_stage":"validated","allowed_next_step":["SOP override active — proceed with CEO accountability"],"override":true}' >&2
    exit 0
  fi
  exit "$code"
}

case "$MODE" in
  pr)
    [[ -n "$PR" ]] || usage
    if [[ -n "$EXEC_JSON_FILE" && -f "$EXEC_JSON_FILE" ]]; then
      export SOP_EXEC_JSON="$(cat "$EXEC_JSON_FILE")"
    fi
    PR_ARGS=(pr "$PR")
    [[ -n "$ACTION" ]] && PR_ARGS+=(--action "$ACTION")
    [[ "$AUTO_RUN" -eq 1 ]] && PR_ARGS+=(--auto-run)
    python3 "$PY" "${PR_ARGS[@]}"
    run_and_exit $?
    ;;
  commit)
    BRANCH="$(git branch --show-current 2>/dev/null || echo "")"
    PATHS="$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null || true)"
    python3 "$PY" commit "$BRANCH" "$PATHS"
    run_and_exit $?
    ;;
  ai)
    if [[ -n "${AI_STDIN:-}" ]]; then
      python3 "$PY" ai
    elif [[ -n "$AI_FILE" && -f "$AI_FILE" ]]; then
      python3 "$PY" ai < "$AI_FILE"
    else
      echo '{"status":"fail","violations":["no AI input"],"sop_stage":"missing","allowed_next_step":["--stdin or --file"]}' >&2
      exit 2
    fi
    run_and_exit $?
    ;;
esac
