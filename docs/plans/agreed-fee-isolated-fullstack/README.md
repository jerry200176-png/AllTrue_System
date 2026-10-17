# Agreed-fee isolated full-stack — proposed artifacts (inert)

All files here are **drafts**. None are under `.github/workflows/` or `frontend/e2e/` yet, so GitHub Actions will not auto-execute them.

Canonical plan: [`../2026-10-17-agreed-fee-isolated-fullstack-harness.md`](../2026-10-17-agreed-fee-isolated-fullstack-harness.md)

| File | Future path (after review) |
|---|---|
| `proposed-workflow.yml` | `.github/workflows/agreed-fee-isolated-fullstack.yml` (`workflow_dispatch` + `enable` default false) |
| `agreed-fee-isolated-router.proposed.php` | `backend/tests/Support/agreed-fee-isolated-router.php` |
| `agreed-fee-isolated-seed.proposed.php` | `backend/tests/Support/agreed-fee-isolated-seed.php` |
| `agreed-fee-isolated-stack.proposed.sh` | `scripts/agreed-fee-isolated-stack.sh` |
| `playwright.agreed-fee-isolated.proposed.config.js` | `frontend/playwright.agreed-fee-isolated.config.js` |
| `agreed-fee-isolated-fullstack.proposed.spec.js` | `frontend/e2e/agreed-fee-isolated-fullstack.spec.js` |

Still missing after approval (small follow-up): `vite.agreed-fee-isolated.config.js` + `agreed-fee-isolated.html` mounting `AgreedFeeCorrectionModal` with absolute API base from env (proxy optional).
