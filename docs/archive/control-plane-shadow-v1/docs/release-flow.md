> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# Release Flow — ADR-001 PDP-Gated Deployments

> **Principle**: Merge integrates intent; **deploy** changes production **only** through the PDP control plane.  
> **Authority**: [`docs/adr/ADR-001-single-production-authority.md`](adr/ADR-001-single-production-authority.md)

---

## 1. PR classification (mandatory in PR description)

Every PR must declare one class in the **first line** of the body:

```
Release-Class: SAFE | DEPLOY | RISKY
```

| Class | Definition | Examples | Merge | Production deploy |
|-------|------------|----------|-------|-------------------|
| **SAFE** | No production runtime change | docs, rules, CI config (non-deploy), tests only | CEO or agent+CEO batch | **Never** |
| **DEPLOY** | Intended to change prod after merge | bugfix, frontend, backend API | CEO after review | **PDP-gated Deploy Production** |
| **RISKY** | T3: auth, billing, migration, RFID, session deduct | migrations, payment, `.htaccess` | CEO only | PDP + backup + smoke + rollback plan |

**Detect deployable diff** (same heuristics as deploy workflows):

- `backend/app/**`, `backend/routes/**`, `backend/database/migrations/**`
- `frontend/src/**`, `frontend/package*.json`
- **Not** deployable: `docs/**`, `.cursor/**`, `*.md`, most `backend/tests/**`

---

## 2. End-to-end flow (ADR-001)

```
┌──────────┐    ┌─────────────┐    ┌─────────────┐    ┌────────────────┐    ┌──────────────┐
│ Branch   │───▶│ PR +        │───▶│ Platform    │───▶│ CEO merge      │───▶│ Deploy       │
│ work     │    │ Platform    │    │ Gate (PDP)  │    │ (main)         │    │ Staging      │
└──────────┘    │ Gate        │    └─────────────┘    └────────────────┘    └──────┬───────┘
                └─────────────┘                                                      │
                                                                                     ▼
                ┌─────────────┐    ┌──────────────────────────────────────────────────────────┐
                │ CI tests    │    │ Deploy Production (ONLY SSH path)                        │
                │ (input only)│    │ PDP verify → promotion-enforce → Pi deploy → smoke     │
                └─────────────┘    └──────────────────────────────────────────────────────────┘
```

### Step 1 — Implement (agent or human)

- Branch from latest `origin/main`.
- Tests for DEPLOY/RISKY as applicable.

### Step 2 — PR + Platform Gate

- Platform Gate assembles `ci-artifacts/pdp.json` on PR.
- Control Plane Verify must pass (`pdp_v3.block_merge=false`).

### Step 3 — CI (prerequisite input, NOT deploy authority)

| CI result | Action |
|-----------|--------|
| Green | Required for confidence; does **not** trigger production deploy |
| Red on **required** checks | Fix before merge |
| `deploy.yml` after CI | **Always fails (ADR-001)** — legacy path disabled |

### Step 4 — Merge to main

- Only CEO (or explicit “merge PR #N”).
- **No force push.**

### Step 5 — Deploy (PDP-gated, automatic chain)

On deployable `main` push:

1. **`Deploy Staging`** — PDP verify + staging promotion artifact
2. **`Deploy Production`** — triggered on staging success:
   - `control-plane-verify.sh`
   - `promotion-enforce.py --target production` (`allow_production`, staging fresh)
   - SSH deploy to Pi (health + smoke + auto-rollback)

Manual dispatch: `gh workflow run "Deploy Production" -f commit_sha=<sha>`

**Deprecated — do not use:**

| Method | Status |
|--------|--------|
| `deploy.yml` after CI | **DISABLED** |
| `Deploy-Approved` label alone | **Not deploy authority** |
| `scripts/deploy-to-pi.sh` without break-glass | **Blocked** |

### Step 6 — Post-deploy verification

```bash
./scripts/release-check.sh
curl -sk https://daan.lifenet.com.tw/api/v1/health
```

---

## 3. Hotfix path

1. `fix/*` or `hotfix/*` branch from production-aligned commit.
2. PR class **RISKY** or **DEPLOY**; Platform Gate must pass.
3. CEO merge → automatic PDP staging → production chain (or manual `Deploy Production`).
4. Postmortem if break-glass used.

---

## 4. Related documents

- [`adr/ADR-001-single-production-authority.md`](adr/ADR-001-single-production-authority.md)
- [`production-truth-model.md`](production-truth-model.md)
- [`engineering-system.md`](engineering-system.md)
- [`RUNBOOK_ROLLBACK.md`](RUNBOOK_ROLLBACK.md)
