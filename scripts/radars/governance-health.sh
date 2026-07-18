#!/usr/bin/env bash
# Governance Health Radar — operational scanner (P3 single radar).
# Owner: Founder / CTO Agent
# Cadence: weekly (manual or CI)
# Policy: docs/radars/README.md
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT_DIR="$ROOT/docs/radars/runs"
mkdir -p "$OUT_DIR"
JSON="$OUT_DIR/governance-health-$STAMP.json"
SUM="$OUT_DIR/governance-health-latest.md"
LOG=$(mktemp)

severity_fail=0
severity_warn=0

run_check() {
  local name="$1"
  local cmd="$2"
  local sev="$3" # fail|warn
  echo "=== $name ===" | tee -a "$LOG"
  set +e
  bash -c "$cmd" >>"$LOG" 2>&1
  local rc=$?
  set -e
  if [[ $rc -ne 0 ]]; then
    echo "result: FAIL rc=$rc severity=$sev" | tee -a "$LOG"
    if [[ "$sev" == "fail" ]]; then
      severity_fail=$((severity_fail + 1))
    else
      severity_warn=$((severity_warn + 1))
    fi
    return 0
  fi
  echo "result: PASS" | tee -a "$LOG"
}

REV=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)

run_check "instruction_invariants" "bash scripts/governance/check-instruction-invariants.sh" fail
run_check "capability_registry" "python3 scripts/governance/validate-capability-registry.py" fail
run_check "overlay_pin" "bash scripts/governance/check-overlay-pin.sh" fail
run_check "agent_preflight_self" "bash scripts/agent-preflight.sh" fail

# Broken SOP links (deterministic subset)
run_check "canonical_entrypoints" "test -f AGENTS.md && test -f docs/governance/COMPANY_CONSTITUTION.md && test -f docs/governance/WORKTREE_POLICY.md && test -f docs/sop/AGENT_PREFLIGHT.md" fail

# Skills/rules referenced
run_check "cursor_adapters_present" "test -f .cursor/rules/no-premature-closure.mdc && test -f .cursor/skills/agent-preflight/SKILL.md" warn

# Self last-run: write pointer
OVERALL="PASS"
[[ $severity_fail -eq 0 ]] || OVERALL="FAIL"

cat >"$JSON" <<EOF
{
  "radar": "governance_health",
  "revision": "$REV",
  "ran_at": "$STAMP",
  "overall": "$OVERALL",
  "fail_count": $severity_fail,
  "warn_count": $severity_warn,
  "owner": "Founder / CTO Agent",
  "cadence": "weekly",
  "remediation": "docs/radars/README.md + failing script output"
}
EOF

{
  echo "# Governance Health — latest run"
  echo
  echo "- revision: \`$REV\`"
  echo "- ran_at: \`$STAMP\`"
  echo "- overall: **$OVERALL** (fail=$severity_fail warn=$severity_warn)"
  echo "- owner: Founder / CTO Agent"
  echo "- cadence: weekly"
  echo "- machine output: \`docs/radars/runs/governance-health-$STAMP.json\`"
  echo
  echo "## Checks"
  echo
  echo '```'
  cat "$LOG"
  echo '```'
  echo
  echo "## False positives"
  echo "Overlay fetch may fail offline — set OVERLAY_FILE= to a local sunrise OVERLAY.md."
  echo
  echo "## Remediation"
  echo "Fix failing script; re-run \`make governance-health\`."
} >"$SUM"

cp "$SUM" "$OUT_DIR/governance-health-$STAMP.md"
rm -f "$LOG"

echo "governance-health: $OVERALL fail=$severity_fail warn=$severity_warn json=$JSON"
[[ "$OVERALL" == "PASS" ]]
