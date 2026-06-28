#!/usr/bin/env bash
# platform-gate.sh — Pure aggregator: collect → assemble → artifact (zero policy logic).
#
# Usage: ./scripts/platform/platform-gate.sh --pr <id> [--out artifact.json]

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"
export REPO_ROOT

PR=""
OUT=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR="$2"; shift 2 ;;
    --out) OUT="$2"; shift 2 ;;
    *) echo "Usage: $0 --pr <id> [--out artifact.json]" >&2; exit 2 ;;
  esac
done

[[ -n "$PR" ]] || { echo "Usage: $0 --pr <id> [--out artifact.json]" >&2; exit 2; }

WORK="${TMPDIR:-/tmp}/platform-gate-$$"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT

run_step() {
  local name="$1"
  shift
  set +e
  local out
  out="$("$@" 2>&1)"
  local code=$?
  set -e
  printf '%s' "$out" > "$WORK/${name}.raw"
  printf '%s' "$code" > "$WORK/${name}.exit"
}

chmod +x scripts/sop-enforce.sh scripts/consistency-check.sh \
  scripts/decision-engine.sh scripts/release-exec.sh \
  scripts/release-check.sh 2>/dev/null || true

run_step sop "$REPO_ROOT/scripts/sop-enforce.sh" --pr "$PR"
run_step consistency "$REPO_ROOT/scripts/consistency-check.sh"
run_step decision "$REPO_ROOT/scripts/decision-engine.sh" analyze --pr "$PR"
run_step exec "$REPO_ROOT/scripts/release-exec.sh" validate --pr "$PR"

python3 "$REPO_ROOT/scripts/platform/platform-gate-assemble.py" \
  --work "$WORK" \
  --pr "$PR" \
  --repo-root "$REPO_ROOT" \
  ${OUT:+--out "$OUT"}
