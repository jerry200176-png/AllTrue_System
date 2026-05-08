# Engineering Maturity Gaps

This file tracks CI/CD and process gaps that block reliable day-to-day delivery.

## 2026-05-08 — CI required checks vs docs-only path filters

- **Problem**: Docs-only PRs could not merge because branch protection required `PHPUnit Feature & Unit Tests` and `Vite Frontend Build`, but those checks were missing when `ci.yml` was skipped by workflow-level `paths`.
- **Decision**: Keep `ci.yml` always triggered on PR/push to `main`, and rely on `Detect changed areas` + job-level `if` conditions to skip heavy jobs while still emitting stable required-check contexts.
- **Safety outcome**:
  - docs-only PRs can merge without admin bypass;
  - backend/frontend/workflow changes still run their real gates;
  - docs-only merge remains non-deployable under `deploy.yml` diff guard.

## Follow-up

- Re-audit branch protection required contexts after any workflow rename.
- Keep required check names stable unless a coordinated branch-protection update is prepared in the same change window.
