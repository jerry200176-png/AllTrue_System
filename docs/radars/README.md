# Radars

| Radar | Status | Command | Owner | Cadence |
|-------|--------|---------|-------|---------|
| **Governance Health** | **Operational** | `make governance-health` / `bash scripts/radars/governance-health.sh` | Founder / CTO Agent | Weekly |
| **Technical Health** | **Operational** | `make technical-health` / `bash scripts/radars/technical-health.sh` | Founder / CTO Agent | Weekly |
| **Technical Health Scorecard** | **Operational** | `make technical-health-scorecard` / `python3 scripts/radars/technical-health-scorecard.py` | Founder / CTO Agent | Monthly |
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

## Technical Health Scorecard (monthly)

The scorecard is a read-only aggregation for #904. It combines the last 30
completed `main` CI runs, the latest two successful CI coverage artifacts,
PHPStan baseline entries versus a roughly 30-day git reference, open P1/P2
technical-debt candidates, recurring-defect families, and production source
files touched by more than five bug-fix commits in the last 90 days.

**Output:** `docs/radars/runs/technical-health-scorecard-YYYY-MM.json` and `.md`

Coverage or GitHub API evidence that is unavailable is printed as
`unavailable`, never converted to zero. Labels are candidate filters only;
roadmap candidates require a separate current-state issue/body/runtime review.

Architecture/Product/KPI remain **scaffold** until they have commands + run artifacts.
