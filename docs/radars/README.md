# Radars

| Radar | Status | Command | Owner | Cadence |
|-------|--------|---------|-------|---------|
| **Governance Health** | **Operational** | `make governance-health` / `bash scripts/radars/governance-health.sh` | Founder / CTO Agent | Weekly |
| Architecture | scaffold | — | — | — |
| Technical | scaffold | — | — | — |
| Product | scaffold | — | — | — |
| Engineering KPI | scaffold | — | — | — |

## Governance Health (operational)

**Inputs:** Constitution, WORKTREE_POLICY, capability YAML, sunrise OVERLAY pin, AGENTS/CLAUDE invariants, adapter files.  
**Output:** `docs/radars/runs/governance-health-*.json` + `governance-health-latest.md`  
**Severity:** instruction/capability/overlay/preflight → fail; missing Cursor adapters → warn  
**False positives:** offline overlay fetch — set `OVERLAY_FILE=`  
**Last-run evidence:** `docs/radars/runs/governance-health-latest.md` (committed after real runs)  
**Remediation:** fix failing script; do not edit scores by hand  

Architecture/Technical/Product/KPI remain **scaffold** until they have commands + run artifacts.
