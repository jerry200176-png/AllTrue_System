# Radars

| Radar | Status | Command | Owner | Cadence |
|-------|--------|---------|-------|---------|
| **Governance Health** | **Operational** | `make governance-health` / `bash scripts/radars/governance-health.sh` | Founder / CTO Agent | Weekly |
| **Technical Health** | **Operational** | `make technical-health` / `bash scripts/radars/technical-health.sh` | Founder / CTO Agent | Weekly |
| Architecture | scaffold | — | — | — |
| Product | scaffold | — | — | — |
| Engineering KPI | scaffold | — | — | — |

## Governance Health (operational)

**Inputs:** Constitution, WORKTREE_POLICY, capability YAML, sunrise OVERLAY pin, AGENTS/CLAUDE invariants, adapter files.  
**Output:** `docs/radars/runs/governance-health-*.json` + `governance-health-latest.md`  
**Severity:** instruction/capability/overlay/preflight → fail; missing Cursor adapters → warn  
**False positives:** offline overlay fetch — set `OVERLAY_FILE=`  
**Last-run evidence:** `docs/radars/runs/governance-health-latest.md` (committed after real runs)  
**Remediation:** fix failing script; do not edit scores by hand  

## Technical Health (operational)

**Inputs:** `backend/composer.json` `config.platform.php` (or `require.php`); `.github/workflows/*.yml` `php-version` / `node-version`.  
**Checks:**
1. CI PHP major.minor must match composer platform PHP (fail)
2. Workflows must not pin more than one Node major (warn)

**Out of scope (intentionally):** mass Action SHA pinning (high churn / low user ROI); Dependabot CVEs (use GitHub Dependabot alerts — currently the control plane).  
**Output:** `docs/radars/runs/technical-health-*.json` + `technical-health-latest.md`  
**Last-run evidence:** `docs/radars/runs/technical-health-latest.md`  
**Remediation:** align workflow pins; do not “fix” by editing the radar score  

Architecture/Product/KPI remain **scaffold** until they have commands + run artifacts.
