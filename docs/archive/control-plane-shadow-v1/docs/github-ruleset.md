> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# GitHub Ruleset — Platform Gate Hard Gate

> **PDP is the source of truth.** `policy-engine.py` decides merge and promotion. CI workflows are execution layers that read PDP output only.

## Required configuration (branch: `main`)

| Control | Setting | Why |
|---------|---------|-----|
| Required status check | **Platform Gate** | Job name in `.github/workflows/platform-gate.yml` |
| Block direct push | Require pull request before merging | No bypass of PDP artifact path |
| Block force push | Disallow force pushes | Auditable linear promotion chain |
| Linear history | Require linear history | One commit per merge |
| No merge without PDP artifact | Platform Gate check must pass | GitHub blocks merge when `pdp.block_merge=true` |

## Architecture

```
Execution layers (telemetry) → platform-gate.sh → policy-engine.py (PDP)
                                                      ↓
                              platform-gate.yml reads pdp.block_merge ONLY
```

- **PDP** (`scripts/platform/policy-engine.py`): sole decision authority.
- **CI** (`platform-gate.yml`): runs collector, uploads artifact, fails **only** when `pdp.block_merge == true`.
- **Promotion** (`deploy-staging.yml`, `deploy-production.yml`): read `pdp.promotion.*` only (+ staging artifact freshness for production).

Local hooks remain advisory; they cannot enforce merge on GitHub.

## Setup (GitHub UI)

1. **Settings → Rules → Rulesets → New branch ruleset**
2. Target: `main`
3. Enable:
   - Require a pull request before merging
   - Require status checks → **Platform Gate**
   - Block force pushes
   - Require linear history
4. Enforcement: **Active**

Classic path: **Settings → Branches → Branch protection rules** (same controls).

## Audit

```bash
./scripts/platform/github-ruleset-audit.sh --branch main
```

```json
{
  "ruleset_status": "PASS|FAIL",
  "missing_checks": []
}
```

Requires `gh` with repo admin read. Does not modify settings.

## Rollback

Disable ruleset in GitHub UI. PDP and workflows continue; merge blocking moves back to CI-only until ruleset re-enabled.
