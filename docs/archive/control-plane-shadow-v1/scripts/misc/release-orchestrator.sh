#!/usr/bin/env bash
# release-orchestrator.sh — Control plane: combines DIL + Execution Layer (read-only).
#
# Flow:
#   1. decision-engine.sh analyze
#   2. release-exec.sh validate
#   3. decision-simulate.sh
#   4. Combined recommendation (NO merge/deploy unless CEO runs release-exec directly)
#
# Usage:
#   ./scripts/release-orchestrator.sh --pr <id>
#   ALLTRUE_ORCH_DRY_RUN=1 ./scripts/release-orchestrator.sh --pr <id>  # default

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export REPO_ROOT

PR=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

[[ -n "$PR" ]] || { echo "Usage: $0 --pr <id>" >&2; exit 2; }

DECISION="$(mktemp)"
EXEC="$(mktemp)"
SIM="$(mktemp)"
trap 'rm -f "$DECISION" "$EXEC" "$SIM"' EXIT

./scripts/decision-engine.sh analyze --pr "$PR" > "$DECISION" 2>/dev/null || echo '{}' > "$DECISION"

if [[ -x ./scripts/release-exec.sh ]]; then
  ./scripts/release-exec.sh validate --pr "$PR" > "$EXEC" 2>/dev/null || true
else
  echo '{"status":"unknown"}' > "$EXEC"
fi

./scripts/decision-simulate.sh --pr "$PR" > "$SIM" 2>/dev/null || echo '{"simulation_result":"DANGEROUS"}' > "$SIM"

python3 - <<'PY' "$DECISION" "$EXEC" "$SIM" "$PR"
import json, sys

decision = json.load(open(sys.argv[1]))
exec_out = json.load(open(sys.argv[2]))
sim = json.load(open(sys.argv[3]))
pr_id = sys.argv[4]

exec_status = exec_out.get("status", "unknown")
exec_class = exec_out.get("release_class", decision.get("change_class"))
sim_result = sim.get("simulation_result", "WARNING")
strategy = decision.get("recommended_strategy", "staged_release")
risk_level = decision.get("risk_level", "medium")

# Combined gate: intelligence + execution + simulation
final = "PROCEED_WITH_CAUTION"
blockers = []
if sim_result == "DANGEROUS":
    blockers.append("Simulation: DANGEROUS")
if exec_status == "blocked":
    blockers.append("Execution layer: blocked")
if strategy == "block":
    blockers.append("Decision engine: block strategy")
if decision.get("risk_level") == "critical":
    blockers.append("Risk level: critical")

if blockers:
    final = "DO_NOT_EXECUTE"
elif sim_result == "WARNING" or exec_status != "allowed" or strategy != "direct_merge":
    final = "PROCEED_WITH_CAUTION"
else:
    final = "READY_FOR_CEO_MERGE"

combined = {
    "pr_id": pr_id,
    "final_recommendation": final,
    "blockers": blockers,
    "decision": {
        "risk_score": decision.get("risk_score"),
        "risk_level": risk_level,
        "change_class": decision.get("change_class"),
        "recommended_strategy": strategy,
        "confidence": decision.get("confidence"),
    },
    "execution": {
        "status": exec_status,
        "release_class": exec_class,
        "reasons": exec_out.get("reasons", [])[:5],
    },
    "simulation": {
        "result": sim_result,
        "failure_probability": sim.get("failure_probability"),
        "rollback_feasible": sim.get("rollback_feasible"),
    },
    "ceo_next_steps": [],
}

steps = []
if final == "DO_NOT_EXECUTE":
    steps.append("Resolve all blockers before any merge/deploy action")
    steps.extend(decision.get("next_actions", [])[:3])
elif final == "PROCEED_WITH_CAUTION":
    steps.append(f"Review simulation ({sim_result}) and execution status ({exec_status})")
    steps.append(f"./scripts/release-exec.sh validate --pr {pr_id}")
    steps.append("Obtain CEO approval env vars before merge/deploy")
else:
    steps.append(f"ALLTRUE_MERGE_APPROVED=yes ./scripts/release-exec.sh merge --pr {pr_id}")
    if exec_class in ("DEPLOY", "RISKY"):
        steps.append(f"After merge: ALLTRUE_DEPLOY_APPROVED=yes ./scripts/release-exec.sh deploy --pr {pr_id}")

combined["ceo_next_steps"] = steps[:6]
combined["note"] = "Orchestrator is advisory. Only release-exec.sh executes merge/deploy."

print(json.dumps(combined, indent=2, ensure_ascii=False))
PY
