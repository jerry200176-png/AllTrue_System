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
| `vite.agreed-fee-isolated.proposed.config.js` | `frontend/vite.agreed-fee-isolated.config.js` (proxies `/api`→`:8000`) |
| `fixture/agreed-fee-isolated.html.proposed` | `frontend/e2e/fixtures/agreed-fee-isolated/agreed-fee-isolated.html` |
| `fixture/agreed-fee-isolated-mount.proposed.js` | `…/agreed-fee-isolated-mount.js` (mounts modal; needs fee UI tip e.g. a579) |

Apply onto a tip that contains `AgreedFeeCorrectionModal` (e.g. #4250 `a579`). Spec asserts `fee-charge` / `fee-plan` / fixture `agreed-fee-success` after apply emit.
