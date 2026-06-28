#!/usr/bin/env bash
# release-check.sh — Compare production truth vs git main (read-only).
#
# Usage:
#   ./scripts/release-check.sh
#   PROD_BASE_URL=https://daan.lifenet.com.tw ./scripts/release-check.sh
#   PI_SSH=admin@pi.lifenet.com.tw ./scripts/release-check.sh   # optional Pi HEAD audit
#
# Exit codes:
#   0 — SYNCED or MAIN_AHEAD or BACKEND_ONLY_LAG (operational OK / pending deploy)
#   1 — PROD_AHEAD, DIVERGED, or UNKNOWN (needs CEO action)
#   2 — usage / dependency error
#
# Does NOT: deploy, merge, SSH write, or trigger CI.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

PROD_BASE_URL="${PROD_BASE_URL:-https://daan.lifenet.com.tw}"
PI_SSH="${PI_SSH:-}"
PI_BACKEND="${PI_BACKEND:-/home/admin/backend}"
JSON_OUT="${JSON_OUT:-0}"

note() { printf '%s\n' "$*"; }
warn() { printf '⚠️  %s\n' "$*" >&2; }

# ── Resolve main HEAD (prefer origin/main) ─────────────────────────────
MAIN_REF="main"
if git rev-parse --verify origin/main >/dev/null 2>&1; then
  MAIN_REF="origin/main"
fi
if ! git rev-parse --verify "$MAIN_REF" >/dev/null 2>&1; then
  note "ERROR: cannot resolve $MAIN_REF"
  exit 2
fi

MAIN_FULL="$(git rev-parse "$MAIN_REF")"
MAIN_SHORT="$(git rev-parse --short=8 "$MAIN_FULL")"

# ── Fetch production version.json (L1) ───────────────────────────────
VER_JSON="$(curl -sf --max-time 15 "${PROD_BASE_URL%/}/version.json" 2>/dev/null || true)"
if [[ -z "$VER_JSON" ]]; then
  PROD_HASH=""
  PROD_TIME=""
  HEALTH="fail"
else
  read -r PROD_HASH PROD_TIME <<EOF
$(python3 - <<'PY' "$VER_JSON"
import json, sys
try:
    d = json.loads(sys.argv[1])
    print(d.get("hash", "").strip(), d.get("t", "").strip())
except Exception:
    print("", "")
PY
)
EOF
  HEALTH="ok"
fi

if curl -sf --max-time 10 "${PROD_BASE_URL%/}/api/v1/health" | grep -q '"status":"ok"'; then
  HEALTH="ok"
else
  HEALTH="degraded"
fi

# ── Resolve prod hash to commit (if possible) ──────────────────────────
PROD_FULL=""
if [[ -n "$PROD_HASH" ]]; then
  if PROD_FULL="$(git rev-parse "${PROD_HASH}^{commit}" 2>/dev/null)"; then
    :
  elif PROD_FULL="$(git rev-parse --short=8 "${PROD_HASH}^{commit}" 2>/dev/null)"; then
    PROD_FULL="$(git rev-parse "$PROD_FULL")"
  else
    # Try walking all refs for short hash prefix
    PROD_FULL="$(git rev-list --all 2>/dev/null | while read -r c; do
      if [[ "$(git rev-parse --short=8 "$c")" == "$PROD_HASH" ]]; then echo "$c"; break; fi
    done)"
  fi
fi

# ── Optional Pi backend HEAD (L2) ───────────────────────────────────────
PI_SHORT=""
PI_FULL=""
if [[ -n "$PI_SSH" ]]; then
  if PI_FULL="$(ssh -o BatchMode=yes -o ConnectTimeout=8 "$PI_SSH" "cd ${PI_BACKEND} && git rev-parse HEAD" 2>/dev/null)"; then
    PI_SHORT="$(git rev-parse --short=8 "$PI_FULL" 2>/dev/null || echo "${PI_FULL:0:8}")"
  else
    warn "Could not read Pi git HEAD via SSH (set PI_SSH or ignore)"
  fi
fi

# ── Classify drift ─────────────────────────────────────────────────────
DRIFT="UNKNOWN"
RISK="high"
ACTION="Investigate: production version.json unreachable or hash not in git."
EXIT=1

is_ancestor_of() {
  git merge-base --is-ancestor "$1" "$2" 2>/dev/null
}

if [[ -z "$PROD_HASH" ]]; then
  DRIFT="UNKNOWN"
  RISK="high"
  ACTION="Fix production version.json or URL; verify deploy pipeline."
elif [[ -z "$PROD_FULL" ]]; then
  DRIFT="UNKNOWN"
  RISK="high"
  ACTION="Prod hash '$PROD_HASH' not found in local git; fetch --all or clone full history."
else
  PROD_SHORT="$(git rev-parse --short=8 "$PROD_FULL")"

  if [[ "$PROD_FULL" == "$MAIN_FULL" ]]; then
    DRIFT="SYNCED"
    RISK="low"
    ACTION="No sync required. Production frontend hash matches main HEAD."
    EXIT=0
  elif is_ancestor_of "$PROD_FULL" "$MAIN_FULL"; then
    if [[ -n "$PI_FULL" && "$PI_FULL" == "$MAIN_FULL" && "$PROD_FULL" != "$MAIN_FULL" ]]; then
      DRIFT="BACKEND_ONLY_LAG"
      RISK="low"
      ACTION="Backend on main; version.json stale (backend-only deploy). OK if intentional."
      EXIT=0
    else
      DRIFT="MAIN_AHEAD"
      RISK="low"
      ACTION="Main ahead of production. CEO deploy when ready (see docs/release-flow.md)."
      EXIT=0
    fi
  elif is_ancestor_of "$MAIN_FULL" "$PROD_FULL"; then
    DRIFT="PROD_AHEAD"
    RISK="high"
    ACTION="Production commit not merged to main. Merge branch or revert prod within 24h."
    EXIT=1
  elif [[ -n "$PI_FULL" && "$PI_FULL" != "$PROD_FULL" ]]; then
    DRIFT="DIVERGED"
    RISK="high"
    ACTION="L1 (version.json) and L2 (Pi HEAD) disagree. Stop deploys; CEO incident review."
    EXIT=1
  else
    DRIFT="DIVERGED"
    RISK="medium"
    ACTION="Production and main share no linear ancestor relationship. Fetch all refs and compare manually."
    EXIT=1
  fi
fi

# ── Output ────────────────────────────────────────────────────────────
if [[ "$JSON_OUT" == "1" ]]; then
  DRIFT="$DRIFT" RISK="$RISK" ACTION="$ACTION" HEALTH="$HEALTH" \
  MAIN_REF="$MAIN_REF" MAIN_SHORT="$MAIN_SHORT" \
  PROD_HASH="$PROD_HASH" PROD_TIME="$PROD_TIME" PROD_FULL="$PROD_FULL" PI_SHORT="$PI_SHORT" \
  python3 - <<'PY'
import json, os
prod_full = os.environ.get("PROD_FULL", "")
print(json.dumps({
  "drift_status": os.environ.get("DRIFT"),
  "risk_level": os.environ.get("RISK"),
  "recommended_action": os.environ.get("ACTION"),
  "health": os.environ.get("HEALTH"),
  "main_ref": os.environ.get("MAIN_REF"),
  "main_short": os.environ.get("MAIN_SHORT"),
  "prod_version_hash": os.environ.get("PROD_HASH"),
  "prod_version_time": os.environ.get("PROD_TIME"),
  "prod_commit_resolved": prod_full[:8] if prod_full else None,
  "pi_head_short": os.environ.get("PI_SHORT") or None,
}, indent=2))
PY
  if [[ "${SHCL_FEED:-}" == "1" ]]; then
    mkdir -p "$REPO_ROOT/.cursor/.local/shcl"
    JSON_OUT=1 DRIFT="$DRIFT" RISK="$RISK" ACTION="$ACTION" HEALTH="$HEALTH" \
    MAIN_REF="$MAIN_REF" MAIN_SHORT="$MAIN_SHORT" \
    PROD_HASH="$PROD_HASH" PROD_TIME="$PROD_TIME" PROD_FULL="$PROD_FULL" PI_SHORT="$PI_SHORT" \
    python3 - <<'PY2' > "$REPO_ROOT/.cursor/.local/shcl/drift-latest.json"
import json, os
prod_full = os.environ.get("PROD_FULL", "")
print(json.dumps({
  "drift_status": os.environ.get("DRIFT"),
  "risk_level": os.environ.get("RISK"),
  "recommended_action": os.environ.get("ACTION"),
  "health": os.environ.get("HEALTH"),
  "main_ref": os.environ.get("MAIN_REF"),
  "main_short": os.environ.get("MAIN_SHORT"),
  "prod_version_hash": os.environ.get("PROD_HASH"),
  "prod_version_time": os.environ.get("PROD_TIME"),
  "prod_commit_resolved": prod_full[:8] if prod_full else None,
  "pi_head_short": os.environ.get("PI_SHORT") or None,
  "fed_by": "release-check.sh",
}, indent=2))
PY2
  fi
else
  note "═══════════════════════════════════════════════════════════"
  note " AllTrue Release Check (read-only)"
  note "═══════════════════════════════════════════════════════════"
  note "Health:              $HEALTH"
  note "Main ($MAIN_REF):     $MAIN_SHORT"
  note "Prod version.json:   hash=$PROD_HASH  t=$PROD_TIME"
  [[ -n "$PROD_FULL" ]] && note "Prod hash → commit:  $(git rev-parse --short=8 "$PROD_FULL")"
  [[ -n "$PI_SHORT" ]] && note "Pi backend HEAD:     $PI_SHORT"
  note "───────────────────────────────────────────────────────────"
  note "Drift status:        $DRIFT"
  note "Risk level:          $RISK"
  note "Recommended action:  $ACTION"
  note "═══════════════════════════════════════════════════════════"
  note ""
  note "Docs: docs/production-truth-model.md | docs/release-flow.md"
  note "SHCL: ./scripts/self-heal-engine.sh analyze"
fi

exit "$EXIT"
