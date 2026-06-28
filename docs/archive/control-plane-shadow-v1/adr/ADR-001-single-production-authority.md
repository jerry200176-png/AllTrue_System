> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# ADR-001: Single Production Deployment Authority

| Field | Value |
|-------|-------|
| **Status** | **Superseded** — by [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) (I1–I5). Historical record only. |
| **Date** | 2026-06-27 |
| **Deciders** | CEO / Platform Engineering |
| **Supersedes** | Implicit dual authority (`deploy.yml` auto-deploy + manual SOP) |

---

## Context

AllTrue previously had **two production deployment paths**:

1. **Legacy:** `.github/workflows/deploy.yml` — auto-triggered after `CI — PHPUnit Tests` succeeded on `main`, SSH deploy to Pi with **no PDP gate**.
2. **Control plane:** `platform-gate.yml` + `deploy-staging.yml` + `deploy-production.yml` — PDP-verified promotion artifacts, but **deploy-production did not perform SSH deploy**.

This created **CRITICAL** risk: code could reach production while bypassing `ci-artifacts/pdp.json` authorization (`allow_production`, signature, staging freshness).

Additionally, governance docs (`engineering-system.md`, `release-flow.md`) described CI as **observational / CEO-waivable**, contradicting the fail-closed PDP control plane.

---

## Decision

Establish **one and only one** production deployment authority:

```
┌─────────┐    ┌──────────────────────┐    ┌─────────────────────┐    ┌────────────┐
│ CI      │───▶│ PDP Control Plane    │───▶│ Production Promotion │───▶│ Pi Deploy  │
│ (input) │    │ (mandatory gate)     │    │ (artifact + verify)  │    │ (SSH)      │
└─────────┘    └──────────────────────┘    └─────────────────────┘    └────────────┘
```

### Canonical workflows

| Stage | Workflow | Role |
|-------|----------|------|
| PR merge gate | `Platform Gate` | Assemble + sign `ci-artifacts/pdp.json`; block merge if `block_merge` |
| CI | `CI — PHPUnit Tests` | **Input only** — tests, build signals; **NOT** deploy authority |
| Staging promotion | `Deploy Staging` | On `main` push: PDP verify → staging promotion artifact |
| **Production deploy** | **`Deploy Production`** | **ONLY** workflow that SSH-deploys to Pi |
| Legacy | `Deploy to Pi` (`deploy.yml`) | **DISABLED** — fails closed with ADR-001 error |

### What CI may produce

- Test results (pass/fail)
- Build artifacts (informational)
- Signals consumed by `platform-gate-assemble.py` (sop, decision, exec collectors)

CI **must not** trigger production deploy.

### What PDP must validate (before SSH)

All checks in `scripts/platform/control-plane-verify.sh`:

1. `drift-nullifier.py` — no secondary policy engines
2. `git-policy-audit.py` — schema + no derived PDP authority files
3. `control-plane-lock.py` — structure + binding
4. `pdp-read.py --verify` — commit/run-bound signature
5. `policy-fork-detector.py` — promotion artifact consistency
6. `pdp-replay-guard.py` — replay rejection
7. `github-ruleset-audit.sh --enforce` — branch protection (when not skipped)

Plus `promotion-enforce.py --target production`:

- Valid PDP signature
- `pdp_v3.allow_production == true`
- Staging artifact exists, signed, fresh (max age from PDP)

### Production approval conditions

Production SSH deploy runs **only if**:

| # | Condition | Enforced by |
|---|-----------|-------------|
| 1 | Commit is on `main` | Workflow trigger + checkout |
| 2 | Deployable diff (backend/frontend/scripts) | `detect-deployable` job |
| 3 | PDP control plane verify PASS | `control-plane-verify.sh` |
| 4 | `pdp_v3.allow_production == true` | `promotion-enforce.py` |
| 5 | Valid staging promotion for commit | `promotion-enforce.py` |
| 6 | Health + smoke pass post-deploy | SSH deploy script block |
| 7 | Auto-rollback on health/smoke fail | Same as legacy deploy block |

### Rollback

Unchanged mechanism, triggered **inside** `Deploy Production` SSH block:

1. Record `PREV_COMMIT` before `git reset --hard origin/main`
2. On health/smoke failure → `git reset --hard $PREV_COMMIT` → frontend rebuild if needed → `migrate:rollback --step=1` if migration ran
3. Manual rollback: `git revert` on `main` → re-run `Deploy Production` (PDP-gated)

Break-glass Pi deploy (`ALLTRUE_BREAK_GLASS_DEPLOY=1`) requires CEO manual action and postmortem — **not** available to CI or agents.

---

## Consequences

### Positive

- Single auditable path: every production deploy has PDP artifact + promotion record
- CI decoupled from deploy authority
- Legacy bypass closed (deploy.yml fails closed)

### Negative / trade-offs

- Production deploy no longer automatic on every green CI — requires `Deploy Staging` → `Deploy Production` chain
- Docs and `release-exec.sh` must be updated (this ADR)
- Branch protection must require `Platform Gate` + `Control Plane Verify`

### Deprecated (do not use)

| Path | Status |
|------|--------|
| `deploy.yml` auto-deploy on CI success | **DISABLED** — job exits 1 |
| `Deploy-Approved` label as deploy authority | **Removed** from deploy path |
| `scripts/deploy-to-pi.sh` without break-glass | **Blocked** (exit 2) |
| `release-exec.sh deploy` → trigger CI for deploy.yml | **Replaced** → `gh workflow run "Deploy Production"` |

---

## Platform Enforcement Binding (activation required)

ADR-001 is **not fully effective** until GitHub platform controls are applied **and attestation PASS**.

### GitHub state truth

- `scripts/platform/github-enforcement-attestation.sh` — live API vs `config/github/platform-enforcement.json`
- Output: `ci-artifacts/enforcement/enforcement_state.json` + `enforcement_attestation_hash`
- Required input to all deployment gates (no declared-only trust)

### PDP root-of-trust

- Ed25519 signing via `scripts/platform/pdp_signing_authority.py`
- Public anchor: `config/platform/pdp-signing-authority.pub.pem`
- Private key: GitHub secret `PDP_SIGNING_PRIVATE_KEY`
- Verification: `scripts/platform/verify-pdp-signature.sh [--require-staging]`

### Activation (CEO / repo admin, once)

```bash
bash scripts/platform/apply-platform-enforcement.sh --dry-run   # preview
bash scripts/platform/apply-platform-enforcement.sh             # apply
bash scripts/platform/github-ruleset-audit.sh --branch main --enforce
```

Configure `production-break-glass` environment reviewers in GitHub UI (CEO account).

### Artifact immutability

`ci-artifacts/pdp.json` carries `promotion_integrity`:

- `commit_sha`, `ci_test_hash`, `pdp_decision_hash`, `staging_deployment_hash`, `integrity_signature`

Production deploy verifies via `verify-promotion-integrity.sh` + `verify-github-checks.sh --production`.

### Cannot bypass (platform + runtime)

1. GitHub blocks direct push and requires Platform Gate + Control Plane Verify on merge
2. `deploy.yml` fails closed — zero SSH/deploy steps
3. Auto production path requires Deploy Staging artifacts (no PDP re-assemble fallback)
4. PDP-signed promotion chain must match staging artifact hash
5. Break-glass `workflow_dispatch` requires `production-break-glass` environment approval

---

## Verification checklist

After merge, confirm:

- [ ] `deploy.yml` run after CI shows **ADR-001 block** (exit 1), no SSH
- [ ] `Deploy Staging` on main push produces staging + PDP artifacts with `promotion_integrity`
- [ ] `Deploy Production` is the only workflow with Pi SSH steps (auto + break-glass jobs)
- [ ] `verify-promotion-integrity.sh` rejects hash mismatch
- [ ] `verify-github-checks.sh --production` rejects missing Platform Gate / Staging success
- [ ] `apply-platform-enforcement.sh` applied; `github-ruleset-audit.sh --enforce` PASS
- [ ] GitHub Environments: `staging`, `production-pdp`, `production-break-glass` exist
- [ ] `promotion-enforce.py` rejects when `allow_production=false`
- [ ] `engineering-system.md` / `release-flow.md` reference this ADR

---

## Related

- `docs/pdp-contract.md`
- `docs/github-ruleset-enforcement.md`
- `docs/RUNBOOK_ROLLBACK.md`
