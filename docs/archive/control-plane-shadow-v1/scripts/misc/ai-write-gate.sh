#!/usr/bin/env bash
# ai-write-gate.sh — Policy firewall for AI-generated change proposals.
#
# Usage:
#   ./scripts/ai-write-gate.sh --file path/to/agent-output.md
#   ./scripts/ai-write-gate.sh --stdin
#   echo "..." | ./scripts/ai-write-gate.sh --stdin
#
# Validates agent output contains required governance markers and blocks
# forbidden "ready to deploy" language unless CEO approval env is set.
#
# Exit 0 = pass; 1 = blocked (prints remediation)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib/release-exec-lib.sh
source "$REPO_ROOT/scripts/lib/release-exec-lib.sh"

INPUT=""
MODE="file"
FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --stdin) MODE="stdin"; shift ;;
    --file) MODE="file"; FILE="$2"; shift 2 ;;
    *) echo "Usage: $0 --file PATH | --stdin" >&2; exit 2 ;;
  esac
done

if [[ "$MODE" == "stdin" ]]; then
  INPUT="$(cat)"
elif [[ -n "$FILE" && -f "$FILE" ]]; then
  INPUT="$(cat "$FILE")"
else
  echo "❌ AI WRITE GATE: no input file" >&2
  exit 2
fi

BLOCK=0
REASONS=()

require_line() {
  local pattern="$1" label="$2"
  if ! printf '%s' "$INPUT" | grep -qiE "$pattern"; then
    REASONS+=("Missing required marker: $label")
    BLOCK=1
  fi
}

# Required governance markers
require_line 'Release-Class:\s*(SAFE|DEPLOY|RISKY)' 'Release-Class (SAFE|DEPLOY|RISKY)'
require_line '(Risk-Tier|T[0-3]|risk_level|risk level).*(low|medium|high|T0|T1|T2|T3)' 'Risk classification'
require_line '(Affected modules|affected_modules|影響模組|Modules:)' 'Affected modules summary'
require_line '(Diff summary|diff summary|變更摘要|Summary:)' 'Diff summary'

# Forbidden autonomous deploy language (unless CEO override)
FORBIDDEN='(?<!NOT )ready to deploy|(?<!NOT )已上線|deployed to production|production is live|merge and deploy now|(?<!未)可以直接部署|已部署完成'
if python3 -c "import re,sys; p=r'''$FORBIDDEN'''; sys.exit(0 if re.search(p, sys.stdin.read(), re.I) else 1)" <<< "$INPUT"; then
  if [[ "${ALLTRUE_AI_DEPLOY_LANGUAGE_OK:-}" != "yes" ]]; then
    REASONS+=("Forbidden deploy-ready language detected (AI must not declare production complete)")
    BLOCK=1
  fi
fi

# AI must not claim merge approval
if printf '%s' "$INPUT" | grep -qiE '(merged to main|已 merge|PR merged|gh pr merge)'; then
  if [[ "${ALLTRUE_AI_MERGE_CLAIM_OK:-}" != "yes" ]]; then
    REASONS+=("AI must not claim merge execution — use 'PR ready for CEO merge'")
    BLOCK=1
  fi
fi

CLASS="$(printf '%s' "$INPUT" | grep -oiE 'Release-Class:\s*(SAFE|DEPLOY|RISKY)' | head -1 | grep -oiE '(SAFE|DEPLOY|RISKY)' || echo UNKNOWN)"
RISK="$(exec_map_class_to_risk "$CLASS")"

exec_log_audit "ai-write-gate" "$([[ "$BLOCK" -eq 0 ]] && echo allowed || echo blocked)" \
  "{\"release_class\":\"$CLASS\",\"block_count\":$BLOCK}"

if [[ "$BLOCK" -eq 1 ]]; then
  echo "❌ AI WRITE GATE: BLOCKED" >&2
  for r in "${REASONS[@]}"; do echo "   - $r" >&2; done
  echo "" >&2
  echo "Required template (add to agent output):" >&2
  cat >&2 <<'TEMPLATE'
Release-Class: DEPLOY
Risk-Tier: T2 (medium)
Affected modules: backend/ClassSessionController, frontend/CourseManagement
Diff summary: <what changed and why>
Next step: PR ready for CEO — NOT ready to deploy.
TEMPLATE
  exit 1
fi

echo "✅ AI WRITE GATE: PASS (class=$CLASS risk=$RISK)"
exit 0
