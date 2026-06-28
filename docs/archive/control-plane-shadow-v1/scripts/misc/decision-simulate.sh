#!/usr/bin/env bash
# decision-simulate.sh — Pre-deployment impact simulation (read-only).
#
# Usage:
#   ./scripts/decision-simulate.sh --pr <id>
#
# Output: JSON with simulation_result (SAFE | WARNING | DANGEROUS) and details.
# Does NOT deploy or modify production.

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

if ! command -v python3 >/dev/null 2>&1; then
  echo '{"simulation_result":"DANGEROUS","error":"python3 required"}'
  exit 2
fi

# Reuse decision engine analysis (no side effects)
DECISION_JSON="$(python3 "$REPO_ROOT/scripts/lib/decision_analyze.py" "$PR" 2>/dev/null || echo '{}')"

python3 - <<'PY' "$DECISION_JSON" "$PR"
import json, os, subprocess, sys

decision = json.loads(sys.argv[1]) if sys.argv[1].strip().startswith("{") else {}
pr_id = sys.argv[2]
repo = os.environ["REPO_ROOT"]

drift = {}
try:
    env = {**os.environ, "JSON_OUT": "1"}
    r = subprocess.run(
        [f"{repo}/scripts/release-check.sh"],
        capture_output=True, text=True, timeout=30, env=env,
    )
    if r.stdout.strip():
        drift = json.loads(r.stdout)
except Exception:
    drift = {"drift_status": "UNKNOWN"}

change_class = decision.get("change_class", "UNKNOWN")
risk_score = float(decision.get("risk_score", 0.5))
risk_level = decision.get("risk_level", "medium")
blast = decision.get("blast_radius", [])
rollback = decision.get("rollback_complexity", "medium")
strategy = decision.get("recommended_strategy", "staged_release")

affected_modules = blast
failure_probability = min(0.95, risk_score * 0.85 + (0.1 if drift.get("drift_status") == "DIVERGED" else 0))
unsafe_coupling = len(set(blast) & {"auth", "billing", "session_logic", "student_records"}) >= 2

rollback_feasible = rollback != "high" and drift.get("drift_status") != "DIVERGED"
if change_class == "RISKY" and rollback == "high":
    rollback_feasible = False

warnings = []
dangers = []

if change_class == "RISKY":
    warnings.append("RISKY change class — production validation mandatory")
if unsafe_coupling:
    dangers.append(f"Unsafe coupling across modules: {', '.join(sorted(set(blast) & {'auth','billing','session_logic','student_records'}))}")
if drift.get("drift_status") in ("DIVERGED", "PROD_AHEAD"):
    dangers.append(f"Drift status {drift.get('drift_status')} — deploy may not match intended baseline")
if failure_probability >= 0.65:
    dangers.append(f"Estimated failure probability {failure_probability:.2f} exceeds safety threshold")
elif failure_probability >= 0.35:
    warnings.append(f"Moderate failure probability {failure_probability:.2f}")

if rollback == "high":
    warnings.append("Rollback complexity HIGH — ensure DB backup before deploy")
if strategy == "block":
    dangers.append("Decision engine recommends BLOCK strategy")

sim_result = "SAFE"
if dangers:
    sim_result = "DANGEROUS"
elif warnings or failure_probability >= 0.25 or change_class == "DEPLOY":
    sim_result = "WARNING"

out = {
    "pr_id": pr_id,
    "simulation_result": sim_result,
    "failure_probability": round(failure_probability, 4),
    "affected_modules": affected_modules,
    "unsafe_coupling": unsafe_coupling,
    "rollback_feasible": rollback_feasible,
    "drift_status": drift.get("drift_status", "UNKNOWN"),
    "warnings": warnings,
    "dangers": dangers,
    "recommendation": (
        "Proceed only with CEO approval and post-deploy verification"
        if sim_result == "WARNING"
        else "Do not deploy until mitigations complete"
        if sim_result == "DANGEROUS"
        else "Low simulated impact — still require release-exec validate before merge"
    ),
}
print(json.dumps(out, indent=2, ensure_ascii=False))
PY

exit 0
