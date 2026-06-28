#!/usr/bin/env bash
# release-exec.sh — SINGLE ENTRY POINT for production-affecting actions.
#
# Usage:
#   ./scripts/release-exec.sh validate [--pr N]
#   ./scripts/release-exec.sh status
#   ./scripts/release-exec.sh merge --pr N
#   ./scripts/release-exec.sh deploy [--pr N]
#   ./scripts/release-exec.sh rollback [--pr N|--commit SHA]
#
# Approvals (CEO / human only — never set by AI agents):
#   ALLTRUE_MERGE_APPROVED=yes
#   ALLTRUE_DEPLOY_APPROVED=yes
#   ALLTRUE_RISKY_APPROVED=yes
#   ALLTRUE_DRIFT_ACK=yes
#   ALLTRUE_EXEC_APPROVED=yes   (master override for emergency)
#
# Does NOT: bypass audit log, SSH to Pi, or force-push main.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib/release-exec-lib.sh
source "$REPO_ROOT/scripts/lib/release-exec-lib.sh"
cd "$REPO_ROOT"

CMD="${1:-}"
shift || true

PR=""
COMMIT=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR="$2"; shift 2 ;;
    --commit) COMMIT="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

EXEC_REASONS=""
EXEC_NEXT=""
EXEC_EXTRA_JSON="{}"

finish() {
  local status="$1"
  export EXEC_STATUS="$status"
  export EXEC_ACTION="${EXEC_ACTION:-$CMD}"
  export EXEC_REASONS="${EXEC_REASONS:-}"
  export EXEC_NEXT="${EXEC_NEXT:-}"
  export EXEC_EXTRA_JSON="${EXEC_EXTRA_JSON:-\{\}}"
  export EXEC_CLASS="${EXEC_CLASS:-UNKNOWN}"
  export EXEC_RISK="${EXEC_RISK:-unknown}"
  exec_log_audit "$CMD" "$status" "$EXEC_EXTRA_JSON"
  exec_emit_json
  if [[ "$status" == "allowed" ]]; then
    exit 0
  fi
  exit 1
}

require_gh() {
  if ! command -v gh >/dev/null 2>&1; then
    exec_add_reason "gh CLI not available"
    exec_add_next "Install gh and authenticate: gh auth login"
    finish "blocked"
  fi
}

load_pr_context() {
  require_gh
  [[ -n "$PR" ]] || { exec_add_reason "--pr is required"; finish "blocked"; }
  PR_JSON="$(exec_fetch_pr "$PR")"
  [[ -n "$PR_JSON" ]] || { exec_add_reason "PR #$PR not found"; finish "blocked"; }
  PR_BODY="$(printf '%s' "$PR_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("body") or "")')"
  PR_STATE="$(printf '%s' "$PR_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("state") or "")')"
  PR_FILES="$(printf '%s' "$PR_JSON" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("\n".join(f["path"] for f in d.get("files") or []))')"
  EXEC_CLASS="$(exec_parse_release_class "$PR_BODY")"
  if [[ "$EXEC_CLASS" == "UNKNOWN" ]]; then
    EXEC_CLASS="$(exec_classify_files "$PR_FILES")"
    exec_add_reason "Release-Class missing in PR body; inferred as $EXEC_CLASS from diff"
  fi
  EXEC_RISK="$(exec_map_class_to_risk "$EXEC_CLASS")"
  export EXEC_ACTION="$CMD"
  export PR EXEC_CLASS
  EXEC_EXTRA_JSON="$(python3 -c 'import json,os; print(json.dumps({"pr": int(os.environ["PR"]), "release_class": os.environ["EXEC_CLASS"]}))')"
}

check_drift_gate() {
  local drift_json drift_status
  drift_json="$(exec_drift_snapshot || echo '{}')"
  drift_status="$(printf '%s' "$drift_json" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("drift_status","UNKNOWN"))' 2>/dev/null || echo UNKNOWN)"
  export DRIFT_JSON="$drift_json"
  export EXEC_EXTRA_JSON="${EXEC_EXTRA_JSON:-\{\}}"
  EXEC_EXTRA_JSON="$(python3 -c 'import json,os; b=json.loads(os.environ.get("EXEC_EXTRA_JSON") or "{}"); b["drift"]=json.loads(os.environ.get("DRIFT_JSON") or "{}"); print(json.dumps(b))')"
  case "$drift_status" in
    SYNCED|MAIN_AHEAD|BACKEND_ONLY_LAG) ;;
    *)
      if ! exec_approval_ok drift; then
        exec_add_reason "Production drift: $drift_status (set ALLTRUE_DRIFT_ACK=yes to acknowledge)"
        exec_add_next "Run ./scripts/release-check.sh and resolve drift per docs/production-truth-model.md"
        finish "blocked"
      fi
      exec_add_reason "Drift acknowledged: $drift_status"
      ;;
  esac
}

check_ci_gate() {
  local fails
  fails="$(exec_ci_critical_failures "$PR")"
  if [[ -n "$fails" ]]; then
    if [[ "$EXEC_CLASS" == "SAFE" ]]; then
      exec_add_reason "CI failures on required checks ($fails) but SAFE class — advisory only"
    elif ! exec_approval_ok risky; then
      exec_add_reason "Critical CI checks failing: $fails"
      exec_add_next "Fix CI or set ALLTRUE_RISKY_APPROVED=yes with documented CEO waiver"
      finish "blocked"
    else
      exec_add_reason "CI failures waived by ALLTRUE_RISKY_APPROVED: $fails"
    fi
  fi
}

check_class_gates() {
  case "$CMD" in
    merge)
      if [[ "$EXEC_CLASS" == "RISKY" ]] && ! exec_approval_ok risky; then
        exec_add_reason "RISKY PR requires ALLTRUE_RISKY_APPROVED=yes"
        exec_add_next "Document backup + rollback plan in PR; CEO explicit approval"
        finish "blocked"
      fi
      if [[ "$PR_STATE" != "OPEN" ]]; then
        exec_add_reason "PR #$PR is not OPEN (state=$PR_STATE)"
        finish "blocked"
      fi
      ;;
    deploy)
      if [[ "$EXEC_CLASS" == "SAFE" ]]; then
        exec_add_reason "SAFE releases must not deploy to production"
        exec_add_next "Merge only; deploy.yml should skip non-deployable diff"
        finish "blocked"
      fi
      if ! exec_approval_ok deploy; then
        exec_add_reason "Deploy requires ALLTRUE_DEPLOY_APPROVED=yes"
        exec_add_next "CEO comment Deploy-Approved: yes on PR; then re-run with approval env"
        finish "blocked"
      fi
      if [[ "$EXEC_CLASS" == "RISKY" ]] && ! exec_approval_ok risky; then
        exec_add_reason "RISKY deploy requires ALLTRUE_RISKY_APPROVED=yes"
        finish "blocked"
      fi
      ;;
    rollback)
      if ! exec_approval_ok risky; then
        exec_add_reason "Rollback requires ALLTRUE_RISKY_APPROVED=yes or ALLTRUE_EXEC_APPROVED=yes"
        finish "blocked"
      fi
      ;;
  esac
}

cmd_validate() {
  EXEC_ACTION="validate"
  if [[ -n "$PR" ]]; then
    load_pr_context
    check_drift_gate
    check_ci_gate
    if [[ "$EXEC_CLASS" == "RISKY" ]]; then
      exec_add_next "Obtain ALLTRUE_RISKY_APPROVED=yes before merge/deploy"
    fi
    if [[ "$EXEC_CLASS" == "DEPLOY" ]]; then
      exec_add_next "After merge: ALLTRUE_DEPLOY_APPROVED=yes ./scripts/release-exec.sh deploy --pr $PR"
    fi
    exec_add_next "Merge path: ALLTRUE_MERGE_APPROVED=yes ./scripts/release-exec.sh merge --pr $PR"
    finish "allowed"
  fi

  # Local validate (staged / branch diff)
  local files class
  files="$(exec_staged_files)"
  [[ -n "$files" ]] || files="$(exec_branch_files_vs_main origin/main)"
  class="$(exec_classify_files "$files")"
  export EXEC_CLASS="$class"
  EXEC_RISK="$(exec_map_class_to_risk "$class")"
  EXEC_EXTRA_JSON="$(python3 -c 'import json,os; print(json.dumps({"release_class":os.environ["EXEC_CLASS"],"scope":"local"}))')"
  check_drift_gate
  exec_add_next "Set Release-Class: $class in PR description"
  exec_add_next "Run ./scripts/ai-write-gate.sh on agent output before commit"
  finish "allowed"
}

cmd_status() {
  EXEC_ACTION="status"
  EXEC_CLASS="N/A"
  EXEC_RISK="low"
  local drift_json main_short
  drift_json="$(exec_drift_snapshot || echo '{}')"
  main_short="$(git rev-parse --short=8 origin/main 2>/dev/null || git rev-parse --short=8 main)"
  export MAIN_SHORT="$main_short"
  export DRIFT_JSON="$drift_json"
  EXEC_EXTRA_JSON="$(python3 -c 'import json,os; print(json.dumps({"main_short": os.environ["MAIN_SHORT"], "drift": json.loads(os.environ.get("DRIFT_JSON") or "{}")}))')"
  exec_add_next "Validate PR: ./scripts/release-exec.sh validate --pr <N>"
  finish "allowed"
}

cmd_merge() {
  EXEC_ACTION="merge"
  load_pr_context
  check_drift_gate
  check_ci_gate
  check_class_gates
  if ! exec_approval_ok merge; then
    exec_add_reason "Merge requires ALLTRUE_MERGE_APPROVED=yes (CEO only; AI agents prohibited)"
    exec_add_next "Review ./scripts/release-exec.sh validate --pr $PR output first"
    finish "blocked"
  fi
  if [[ "${ALLTRUE_EXEC_DRY_RUN:-}" == "1" ]]; then
    exec_add_reason "Dry run: merge would execute gh pr merge $PR"
    finish "allowed"
  fi
  gh pr merge "$PR" --merge --delete-branch
  exec_add_reason "Merged PR #$PR via executor"
  if [[ "$EXEC_CLASS" == "DEPLOY" || "$EXEC_CLASS" == "RISKY" ]]; then
    exec_add_next "After merge: ALLTRUE_DEPLOY_APPROVED=yes ./scripts/release-exec.sh deploy (triggers Deploy Production per ADR-001)"
  fi
  exec_add_next "Run ./scripts/release-check.sh after deploy"
  finish "allowed"
}

cmd_deploy() {
  EXEC_ACTION="deploy"
  if [[ -n "$PR" ]]; then
    load_pr_context
  else
    EXEC_CLASS="DEPLOY"
    EXEC_RISK="medium"
    PR="0"
  fi
  check_drift_gate
  check_class_gates

  local health_ok=0
  curl -sf --max-time 10 "${PROD_BASE_URL:-https://daan.lifenet.com.tw}/api/v1/health" | grep -q '"status":"ok"' && health_ok=1
  if [[ "$health_ok" -ne 1 ]]; then
    exec_add_reason "Production health check failed before deploy"
    finish "blocked"
  fi

  # ADR-001: Production deploy ONLY via PDP-gated "Deploy Production" workflow.
  local sha
  sha="$(git rev-parse origin/main 2>/dev/null || echo "")"
  if [[ -z "$sha" ]]; then
    exec_add_reason "Cannot resolve origin/main SHA for Deploy Production workflow"
    finish "blocked"
  fi

  if [[ "${ALLTRUE_EXEC_DRY_RUN:-}" == "1" ]]; then
    exec_add_reason "Dry run: would trigger Deploy Production for $sha"
    finish "allowed"
  fi

  if gh workflow run "Deploy Production" --ref main -f "commit_sha=$sha" 2>/dev/null; then
    exec_add_reason "Triggered Deploy Production (ADR-001) for commit $sha"
    exec_add_next "Monitor: gh run list --workflow=deploy-production.yml --limit 3"
    exec_add_next "Legacy deploy.yml is DISABLED — do not expect auto-deploy after CI"
  else
    exec_add_reason "Could not dispatch Deploy Production workflow"
    exec_add_next "Manual: gh workflow run Deploy Production -f commit_sha=$sha"
    exec_add_next "See docs/adr/ADR-001-single-production-authority.md"
  fi
  finish "allowed"
}

cmd_rollback() {
  EXEC_ACTION="rollback"
  EXEC_CLASS="RISKY"
  EXEC_RISK="high"
  check_class_gates
  local target="${COMMIT:-}"
  [[ -n "$target" ]] || target="$(git rev-parse --short=8 origin/main~1 2>/dev/null || echo unknown)"
  EXEC_EXTRA_JSON="$(python3 -c "import json; print(json.dumps({'rollback_target':'$target'}))")"
  exec_add_reason "Rollback authorized in executor only as plan — no automatic Pi reset"
  exec_add_next "git revert <bad-merge-commit> on branch → release-exec merge"
  exec_add_next "Or Pi: git reset --hard $target per RUNBOOK_ROLLBACK.md (CEO manual)"
  exec_add_next "Verify: ./scripts/release-check.sh && curl /api/v1/health"
  if [[ "${ALLTRUE_EXEC_DRY_RUN:-}" == "1" ]]; then
    finish "allowed"
  fi
  finish "allowed"
}

case "$CMD" in
  validate) cmd_validate ;;
  status)   cmd_status ;;
  merge)    cmd_merge ;;
  deploy)   cmd_deploy ;;
  rollback) cmd_rollback ;;
  *)
    echo "Usage: $0 {validate|status|merge|deploy|rollback} [--pr N] [--commit SHA]" >&2
    exit 2
    ;;
esac
