# Offline Convergence Validation Report

> **CI status:** PENDING — GitHub Actions minutes unavailable at validation time.  
> **Validated locally** on branch stack ending at `chore/index-incident-normalize`.

---

## Local gate order (CI substitute)

```
1. node scripts/control-plane-lint.mjs     → PASS required
2. PHPUnit / Vite / docs-integrity         → defer to CI when available
3. deploy.yml (unchanged semantics)        → triggers only after CI workflow success
```

---

## Per-branch validation (2026-06-27)

| Branch | `control-plane-lint` | Prod deploy workflow | `scripts/platform/` at root | Archive present |
|--------|----------------------|----------------------|----------------------------|-----------------|
| `chore/control-plane-binding` | PASS | `deploy.yml` only | absent | N/A (PR-1) |
| `chore/shadow-quarantine` | PASS | `deploy.yml` only | absent | `docs/archive/control-plane-shadow-v1/` |
| `chore/index-incident-normalize` | PASS | `deploy.yml` only | absent | yes |

---

## Shadow leakage checks (local)

```bash
git ls-files .github/workflows/*.yml | xargs rg -l 'git reset --hard origin/main'
# Expected: .github/workflows/deploy.yml only

test ! -d scripts/platform && echo OK
git ls-files .github/workflows/deploy-production.yml  # must be empty
```

---

## Local working tree cleanup (~/alltrue shadow files)

Untracked shadow files on disk do **not** affect production. After PR #1015 merges:

```bash
# Safe removal — copies exist in docs/archive/control-plane-shadow-v1/
rm -rf scripts/platform config/platform
rm -f .github/workflows/deploy-production.yml .github/workflows/deploy-staging.yml
rm -f .github/workflows/platform-gate.yml .github/workflows/execution-gate.yml
rm -f .github/workflows/runtime-feedback.yml .github/workflows/runtime-policy-sync.yml
```

Or leave in place — `.gitignore` after merge prevents accidental commit.

---

## Rollback

```bash
git checkout backup/pre-convergence-YYYYMMDD
```

---

## Merge order

1. PR #1014 → `main`
2. PR #1015 → after #1014
3. PR #1016 → after #1015
4. CEO: `docs/BRANCH_PROTECTION_UPGRADE.md` when CI available

---

## End-state path

```
CONTROL_PLANE_CONTRACT (I1–I5)
        ↓
control-plane-lint (local PASS; CI pending)
        ↓
CI suite (future)
        ↓
deploy.yml (sole executor)
        ↓
production
```
