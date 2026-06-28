# Branch Protection Upgrade — Control Plane Binding

> **Status:** Proposal — apply after PR #1 (Governance Binding) merges to `main`.  
> **Authority:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) I1–I5

---

## Required status checks (add to `main`)

After merge, add this check to GitHub branch protection:

| Check name (exact) | Source workflow | Purpose |
|--------------------|-----------------|---------|
| **Control Plane Contract Lint** | `CI — PHPUnit Tests` (first job) + `Control Plane Enforce` | Contract I1–I5 validation |

Existing required checks (keep):

- Presubmit Checks
- PHPUnit Feature & Unit Tests
- Vite Frontend Build
- Docs Integrity Check
- PHPStan Advisory (php)
- gitleaks scan
- Golden scenarios report

---

## Apply via GitHub CLI (CEO / admin)

```bash
# 1. Fetch current protection contexts
gh api repos/:owner/:repo/branches/main/protection \
  --jq '.required_status_checks.contexts'

# 2. Patch — append Control Plane Contract Lint (adjust array if API uses checks[] form)
gh api repos/:owner/:repo/branches/main/protection \
  -X PUT \
  -f required_status_checks[strict]=true \
  -f enforce_admins=true \
  -F 'required_status_checks[contexts][]=Presubmit Checks' \
  -F 'required_status_checks[contexts][]=PHPUnit Feature & Unit Tests' \
  -F 'required_status_checks[contexts][]=Vite Frontend Build' \
  -F 'required_status_checks[contexts][]=Docs Integrity Check' \
  -F 'required_status_checks[contexts][]=PHPStan Advisory (php)' \
  -F 'required_status_checks[contexts][]=gitleaks scan' \
  -F 'required_status_checks[contexts][]=Golden scenarios report' \
  -F 'required_status_checks[contexts][]=Control Plane Contract Lint'
```

**UI path:** Settings → Branches → `main` → Require status checks → add **Control Plane Contract Lint**.

---

## CI dependency graph (post PR #1)

```
Control Plane Contract Lint  (ci.yml job: control_plane)
        ↓
Detect changed areas → PHPUnit / Vite / …
        ↓
CI workflow conclusion = success  →  deploy.yml may run (unchanged)
```

**Deploy safety:** `deploy.yml` is **not** modified. Failed contract lint → CI fails → deploy does not trigger.

---

## Contract change rule

PRs that modify `docs/CONTROL_PLANE_CONTRACT.md` must include `[contract-change]` in the title **or** set `CONTRACT_CHANGE=1` in CI env (automatic when title contains tag).

---

## Verification after apply

```bash
gh api repos/:owner/:repo/branches/main/protection \
  --jq '.required_status_checks.contexts'
# Must include "Control Plane Contract Lint"
```
