# Offline CI Gate Binding (documented — not yet GitHub-enforced)

> GitHub Actions unavailable. This documents the **intended** CI dependency graph after merge.

---

## Dependency graph

```
Control Plane Contract Lint  (ci.yml job: control_plane)
        ↓ needs
Detect changed areas → PHPUnit / Vite / …
        ↓
CI workflow conclusion = success
        ↓ workflow_run
deploy.yml (production SSH deploy — UNCHANGED)
```

---

## Local verification (no CI)

```bash
node scripts/control-plane-lint.mjs   # exit 0 = PASS
git diff origin/main -- .github/workflows/deploy.yml  # must be empty for PR-1
```

---

## Activation when CI available

1. Merge PR #1014
2. Confirm `Control Plane Contract Lint` appears as first CI job
3. Apply branch protection per `docs/BRANCH_PROTECTION_UPGRADE.md`

**Do not enable shadow workflows in `.github/workflows/` — see archive.**
