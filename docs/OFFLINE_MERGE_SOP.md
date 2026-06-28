# Offline Merge SOP — When GitHub Actions Minutes Are Unavailable

> **Authority:** [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) I1  
> **Related:** [`BRANCH_PROTECTION_UPGRADE.md`](BRANCH_PROTECTION_UPGRADE.md) · issue #867

---

## When to use

- GitHub Actions minutes exhausted or self-hosted runner down
- PR is merge-ready by code review but required checks cannot run
- CEO/admin approves offline validation as temporary merge authority

**Not for:** skipping tests on T3 safety-critical changes without manual QA evidence.

---

## Pre-merge checklist

1. `git fetch origin`
2. `node scripts/control-plane-lint.mjs` → **PASS** (if PR touches governance paths)
3. `git diff origin/main HEAD -- .github/workflows/deploy.yml` → **0 lines** (unless intentional deploy change with CEO approval)
4. Backend: document manual test evidence in PR comment
5. Frontend-only: `npm run build` locally if frontend changed
6. Save branch protection backup:
   ```bash
   gh api repos/:owner/:repo/branches/main/protection > /tmp/branch-protection-backup.json
   ```

---

## Merge procedure (admin)

1. **Relax required checks temporarily:**
   ```bash
   gh api repos/:owner/:repo/branches/main/protection -X PUT --input /tmp/bp-relax.json
   ```
   (`bp-relax.json`: `required_status_checks: null`, `enforce_admins: true`)

2. **Merge PR:**
   ```bash
   gh pr merge <N> --merge --admin
   ```

3. **Restore protection** (8 checks per FinOps target):
   ```bash
   gh api repos/:owner/:repo/branches/main/protection -X PUT --input /tmp/bp-restore.json
   ```

4. **Post-merge:** `curl -sk https://daan.lifenet.com.tw/api/v1/health` if deployable diff merged

---

## Required status checks (current — max 8)

| Check | Role |
|-------|------|
| Control Plane Contract Lint | Governance gate (root) |
| Presubmit Checks | Repo policy |
| PHPUnit Feature & Unit Tests | Backend regression |
| Vite Frontend Build | Frontend build |
| Docs Integrity Check | Doc links |
| PHPStan Advisory (php) | Static analysis |
| gitleaks scan | Secrets |
| Golden scenarios report | Path → QA traceability |

**Advisory (non-blocking):** Dependency Review, High-risk test gate, Missing tests warn, UI Smoke

---

## FinOps / CI resilience (#867)

- Migrate remaining `ubuntu-latest` jobs to self-hosted runner labels (`wsl-ci`, `alltrue-ci`)
- Add runner health alert (pi-health workflow)
- Target: zero GitHub-hosted minute consumption on PR path

---

## Rollback

If post-merge production issue:
1. `git revert <merge-sha> --no-commit && git commit`
2. PR merge to main (same offline SOP if CI down)
3. Follow [`INCIDENT_START_HERE.md`](INCIDENT_START_HERE.md)
