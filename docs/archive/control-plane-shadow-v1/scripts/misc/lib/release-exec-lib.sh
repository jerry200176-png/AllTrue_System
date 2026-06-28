#!/usr/bin/env bash
# release-exec-lib.sh — shared helpers for the execution layer (source only).
set -euo pipefail

REPO_ROOT="${REPO_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
export EXEC_AUDIT_LOG="${EXEC_AUDIT_LOG:-$REPO_ROOT/.cursor/.local/release-exec-audit.jsonl}"

REQUIRED_CI_CHECKS=(
  "Presubmit Checks"
  "PHPUnit Feature & Unit Tests"
  "Vite Frontend Build"
  "gitleaks scan"
  "Golden scenarios report"
  "Docs Integrity Check"
  "PHPStan Advisory (php)"
)

RISKY_PATH_PATTERNS=(
  'backend/database/migrations/'
  'backend/.env'
  'backend/public/.htaccess'
  'backend/app/Http/Middleware/'
  'SwipeRfidController'
  'SessionDeductionService'
  'AuthController'
  'DIRECTOR_PAYMENT'
  'backend/routes/api.php'
)

DEPLOYABLE_PREFIXES=(
  'backend/app/'
  'backend/routes/'
  'backend/database/migrations/'
  'frontend/src/'
  'frontend/package'
)

exec_log_audit() {
  local action="$1" status="$2" detail="${3:-{}}"
  mkdir -p "$(dirname "$EXEC_AUDIT_LOG")"
  python3 - <<PY "$action" "$status" "$detail"
import json, os, sys, datetime
action, status, detail = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    extra = json.loads(detail) if detail.startswith("{") else {"message": detail}
except Exception:
    extra = {"message": detail}
row = {
    "ts": datetime.datetime.utcnow().isoformat() + "Z",
    "action": action,
    "status": status,
    "user": os.environ.get("USER", "unknown"),
    "host": os.environ.get("HOSTNAME", ""),
    **extra,
}
path = os.environ.get("EXEC_AUDIT_LOG") or ""
if not path:
    sys.exit(0)
with open(path, "a", encoding="utf-8") as f:
    f.write(json.dumps(row, ensure_ascii=False) + "\n")
PY
}

exec_emit_json() {
  python3 - <<'PY'
import json, os, sys
data = {
    "action": os.environ.get("EXEC_ACTION", ""),
    "status": os.environ.get("EXEC_STATUS", "blocked"),
    "risk_level": os.environ.get("EXEC_RISK", "unknown"),
    "release_class": os.environ.get("EXEC_CLASS", "UNKNOWN"),
    "reasons": [r for r in os.environ.get("EXEC_REASONS", "").split("||") if r],
    "next_steps": [s for s in os.environ.get("EXEC_NEXT", "").split("||") if s],
}
if os.environ.get("EXEC_EXTRA_JSON"):
    try:
        data.update(json.loads(os.environ["EXEC_EXTRA_JSON"]))
    except Exception:
        pass
print(json.dumps(data, indent=2, ensure_ascii=False))
PY
}

exec_add_reason() {
  local r="$1"
  if [[ -z "${EXEC_REASONS:-}" ]]; then
    EXEC_REASONS="$r"
  else
    EXEC_REASONS="${EXEC_REASONS}||${r}"
  fi
}

exec_add_next() {
  local s="$1"
  if [[ -z "${EXEC_NEXT:-}" ]]; then
    EXEC_NEXT="$s"
  else
    EXEC_NEXT="${EXEC_NEXT}||${s}"
  fi
}

exec_parse_release_class() {
  local text="$1"
  local from_body=""
  from_body="$(printf '%s' "$text" | grep -oiE 'Release-Class:\s*(SAFE|DEPLOY|RISKY)' | head -1 | grep -oiE '(SAFE|DEPLOY|RISKY)' || true)"
  if [[ -n "$from_body" ]]; then
    printf '%s' "$from_body"
    return 0
  fi
  printf '%s' "UNKNOWN"
}

exec_classify_files() {
  local files="$1"
  local risky=0 deployable=0 safe_only=1

  while IFS= read -r path; do
    [[ -z "$path" ]] && continue
    for p in "${RISKY_PATH_PATTERNS[@]}"; do
      if [[ "$path" == *"$p"* ]]; then risky=1; safe_only=0; fi
    done
    for p in "${DEPLOYABLE_PREFIXES[@]}"; do
      if [[ "$path" == "$p"* ]] || [[ "$path" == *"$p"* ]]; then deployable=1; safe_only=0; fi
    done
    if [[ "$path" != docs/* && "$path" != .cursor/* && "$path" != *.md ]]; then
      if [[ "$path" == backend/* || "$path" == frontend/* || "$path" == scripts/* || "$path" == .github/* ]]; then
        safe_only=0
      fi
    fi
  done <<< "$files"

  if [[ "$risky" -eq 1 ]]; then
    printf 'RISKY'
  elif [[ "$deployable" -eq 1 ]]; then
    printf 'DEPLOY'
  elif [[ "$safe_only" -eq 1 ]]; then
    printf 'SAFE'
  else
    printf 'DEPLOY'
  fi
}

exec_staged_files() {
  git diff --cached --name-only --diff-filter=ACM 2>/dev/null || true
}

exec_branch_files_vs_main() {
  local base="${1:-origin/main}"
  git diff --name-only "$base"...HEAD 2>/dev/null || git diff --name-only "$base" HEAD 2>/dev/null || true
}

exec_map_class_to_risk() {
  case "$1" in
    SAFE) printf 'low' ;;
    DEPLOY) printf 'medium' ;;
    RISKY) printf 'high' ;;
    *) printf 'high' ;;
  esac
}

exec_approval_ok() {
  local kind="$1"
  case "$kind" in
    merge)
      [[ "${ALLTRUE_MERGE_APPROVED:-}" == "yes" || "${ALLTRUE_EXEC_APPROVED:-}" == "yes" ]]
      ;;
    deploy)
      [[ "${ALLTRUE_DEPLOY_APPROVED:-}" == "yes" || "${ALLTRUE_EXEC_APPROVED:-}" == "yes" ]]
      ;;
    risky)
      [[ "${ALLTRUE_RISKY_APPROVED:-}" == "yes" || "${ALLTRUE_EXEC_APPROVED:-}" == "yes" ]]
      ;;
    drift)
      [[ "${ALLTRUE_DRIFT_ACK:-}" == "yes" || "${ALLTRUE_EXEC_APPROVED:-}" == "yes" ]]
      ;;
    main_override)
      [[ "${ALLTRUE_MAIN_OVERRIDE:-}" == "yes" || "${ALLTRUE_EXEC_APPROVED:-}" == "yes" ]]
      ;;
    *)
      false
      ;;
  esac
}

exec_fetch_pr() {
  local pr="$1"
  gh pr view "$pr" --json number,title,body,state,mergeable,headRefName,baseRefName,statusCheckRollup,files 2>/dev/null
}

exec_ci_critical_failures() {
  local pr="$1"
  local failures=""
  failures="$(gh pr view "$pr" --json statusCheckRollup -q '
    [.statusCheckRollup[]?
      | select(.conclusion == "FAILURE")
      | select(.name as $n |
          $n == "Presubmit Checks" or
          $n == "PHPUnit Feature & Unit Tests" or
          $n == "Vite Frontend Build" or
          $n == "gitleaks scan" or
          $n == "Golden scenarios report" or
          $n == "Docs Integrity Check" or
          $n == "PHPStan Advisory (php)"
        )
      | .name] | join(",")
  ' 2>/dev/null || true)"
  printf '%s' "$failures"
}

exec_drift_snapshot() {
  JSON_OUT=1 "$REPO_ROOT/scripts/release-check.sh" 2>/dev/null || true
}
