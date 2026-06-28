#!/usr/bin/env bash
# pre-commit-exec-guard.sh — Enforces execution-layer policy at commit time.
# Installed via scripts/install-git-hooks.sh (pre-commit chain).
#
# Blocks:
#   - Direct commits on main without ALLTRUE_MAIN_OVERRIDE=yes
#   - Production-critical path changes without RELEASE_CLASS declaration
#   - Commits missing executor stamp when merge bypass env detected
#
# Override (CEO emergency only): ALLTRUE_MAIN_OVERRIDE=yes git commit ...

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib/release-exec-lib.sh
source "$REPO_ROOT/scripts/lib/release-exec-lib.sh"

# 0. SOP enforcement (before execution-layer checks)
if [[ -x "$REPO_ROOT/scripts/sop-enforce.sh" ]]; then
  if ! "$REPO_ROOT/scripts/sop-enforce.sh" --commit >/dev/null 2>&1; then
    echo "❌ SOP ENFORCE: Commit blocked — SOP validation failed." >&2
    "$REPO_ROOT/scripts/sop-enforce.sh" --commit 2>&1 | python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
    for v in d.get('violations',[]): print(f'   - {v}', file=sys.stderr)
    for s in d.get('allowed_next_step',[]): print(f'   → {s}', file=sys.stderr)
except Exception: pass
" || true
    exec_log_audit "pre-commit" "blocked" "{\"reason\":\"sop_enforce_fail\"}"
    exit 1
  fi
fi

BRANCH="$(git branch --show-current 2>/dev/null || echo "")"
STAGED="$(exec_staged_files)"

if [[ "$BRANCH" == "main" || "$BRANCH" == "master" ]]; then
  if ! exec_approval_ok main_override; then
    echo "❌ EXEC GUARD: Direct commits to '$BRANCH' are forbidden." >&2
    echo "   Use feature branch → PR → ./scripts/release-exec.sh merge --pr N" >&2
    echo "   Emergency override: ALLTRUE_MAIN_OVERRIDE=yes (CEO only)" >&2
    exec_log_audit "pre-commit" "blocked" "{\"branch\":\"$BRANCH\",\"reason\":\"main_direct_commit\"}"
    exit 1
  fi
  echo "⚠️  EXEC GUARD: main commit allowed via ALLTRUE_MAIN_OVERRIDE (audit logged)" >&2
  exec_log_audit "pre-commit" "allowed" "{\"branch\":\"$BRANCH\",\"override\":\"main\"}"
fi

# Production-critical paths (api / database / ai policy)
CRITICAL=0
CRITICAL_HITS=""
while IFS= read -r path; do
  [[ -z "$path" ]] && continue
  case "$path" in
    backend/routes/*|backend/routes/api.php|backend/database/*|backend/database/migrations/*)
      CRITICAL=1; CRITICAL_HITS+="$path "
      ;;
    .cursor/*|.cursor/rules/*|AGENTS.md|.cursorrules)
      CRITICAL=1; CRITICAL_HITS+="$path "
      ;;
  esac
done <<< "$STAGED"

if [[ "$CRITICAL" -eq 1 ]]; then
  REL_CLASS="${RELEASE_CLASS:-}"
  if [[ -z "$REL_CLASS" && -f "$REPO_ROOT/.cursor/.local/release-class" ]]; then
    REL_CLASS="$(tr -d '[:space:]' < "$REPO_ROOT/.cursor/.local/release-class")"
  fi
  if [[ -z "$REL_CLASS" ]]; then
    INFERRED="$(exec_classify_files "$STAGED")"
    if [[ "$INFERRED" == "RISKY" || "$INFERRED" == "DEPLOY" ]]; then
      echo "❌ EXEC GUARD: Critical paths changed without RELEASE_CLASS declaration." >&2
      echo "   Paths: $CRITICAL_HITS" >&2
      echo "   Set: RELEASE_CLASS=SAFE|DEPLOY|RISKY git commit ..." >&2
      echo "   Or write class to .cursor/.local/release-class (local only)" >&2
      echo "   Inferred class: $INFERRED — run ./scripts/release-exec.sh validate" >&2
      exec_log_audit "pre-commit" "blocked" "{\"critical\":true,\"inferred\":\"$INFERRED\"}"
      exit 1
    fi
  fi
  exec_log_audit "pre-commit" "allowed" "{\"critical\":true,\"release_class\":\"${REL_CLASS:-inferred-safe}\"}"
fi

# Detect merge without executor (git MERGE_HEAD present + no stamp)
if [[ -f .git/MERGE_HEAD ]] && [[ "${ALLTRUE_EXEC_MERGE_STAMP:-}" != "1" ]]; then
  if ! exec_approval_ok main_override; then
    echo "❌ EXEC GUARD: Local merge detected. Use ./scripts/release-exec.sh merge --pr N instead." >&2
    echo "   Bypass (not recommended): ALLTRUE_MAIN_OVERRIDE=yes" >&2
    exec_log_audit "pre-commit" "blocked" "{\"reason\":\"merge_bypass\"}"
    exit 1
  fi
fi

exit 0
