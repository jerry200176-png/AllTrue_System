# Agreed-fee isolated full-stack — proposed artifacts (inert)

All files here are **drafts**. Nothing under `.github/workflows/` — Actions will not auto-run them.

Canonical plan: [`../2026-10-17-agreed-fee-isolated-fullstack-harness.md`](../2026-10-17-agreed-fee-isolated-fullstack-harness.md)

## Install map (after review)

| Draft file | Runnable path |
|---|---|
| `db-guard.proposed.sh` | `scripts/agreed-fee-isolated-db-guard.sh` (**before** migrate) |
| `db-guard.proposed.php` | `backend/tests/Support/agreed-fee-isolated-db-guard.php` |
| `agreed-fee-isolated-router.proposed.php` | `backend/tests/Support/agreed-fee-isolated-router.php` |
| `agreed-fee-isolated-seed.proposed.php` | `backend/tests/Support/agreed-fee-isolated-seed.php` |
| `agreed-fee-isolated-stack.proposed.sh` | `scripts/agreed-fee-isolated-stack.sh` |
| `vite.agreed-fee-isolated.proposed.config.js` | `frontend/vite.agreed-fee-isolated.config.js` |
| `playwright.agreed-fee-isolated.proposed.config.js` | `frontend/playwright.agreed-fee-isolated.config.js` |
| `agreed-fee-isolated-fullstack.proposed.spec.js` | `frontend/e2e/agreed-fee-isolated-fullstack.spec.js` |
| `fixture/*` | `frontend/e2e/fixtures/agreed-fee-isolated/` |
| `proposed-workflow.yml` | `.github/workflows/…` only after review (`enable` default false) |

## Review fixes in this draft

- DDL only after shell+PHP DB guards (name=`AllTrue_test`, loopback host, refuse `DATABASE_URL`/`DB_SOCKET`/read-host).
- Router sets process-local `APP_KEY` (= phpunit.xml) to avoid `signing_unavailable` 503.
- Modal uses relative `/api`; Vite proxies `/api` + `/__isolated` same-origin.
- Spec asserts preview no-write / apply / replay / stale / no receipt via `/__isolated/fee-snapshot` + response JSON (not URL counts alone).
- Seed matches `AgreedFeeFixture` field contract; env file mode `0600`; no token in stdout/artifacts.
- Proposed workflow: `candidate_sha` + `harness_sha` env-bound, 40-hex validated; companion PHPUnit before browser seed.

## Dual-source bind

- **candidate_sha**: tip containing `AgreedFeeCorrection*` + modal (e.g. #4250 `a579…`).
- **harness_sha**: this draft tip under `docs/plans/agreed-fee-isolated-fullstack`.
