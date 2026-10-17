# Agreed-fee isolated full-stack — proposed artifacts (inert)

All files here are **drafts**. Nothing under `.github/workflows/` — Actions will not auto-run them.

Canonical plan: [`../2026-10-17-agreed-fee-isolated-fullstack-harness.md`](../2026-10-17-agreed-fee-isolated-fullstack-harness.md)

## Install map (after review)

| Draft file | Runnable path |
|---|---|
| `db-guard.proposed.sh` | `scripts/agreed-fee-isolated-db-guard.sh` (**before** migrate) |
| `db-guard.proposed.php` | `backend/tests/Support/agreed-fee-isolated-db-guard.php` |
| `agreed-fee-isolated-migrate.proposed.php` | `backend/tests/Support/agreed-fee-isolated-migrate.php` (**same-process** guard→DDL; never plain artisan) |
| `agreed-fee-isolated-router.proposed.php` | `backend/tests/Support/agreed-fee-isolated-router.php` |
| `agreed-fee-isolated-seed.proposed.php` | `backend/tests/Support/agreed-fee-isolated-seed.php` |
| `agreed-fee-isolated-stack.proposed.sh` | `scripts/agreed-fee-isolated-stack.sh` |
| `vite.agreed-fee-isolated.proposed.config.js` | `frontend/vite.agreed-fee-isolated.config.js` |
| `playwright.agreed-fee-isolated.proposed.config.js` | `frontend/playwright.agreed-fee-isolated.config.js` |
| `agreed-fee-isolated-fullstack.proposed.spec.js` | `frontend/e2e/agreed-fee-isolated-fullstack.spec.js` |
| `fixture/*` | `frontend/e2e/fixtures/agreed-fee-isolated/` |
| `proposed-workflow.yml` | `.github/workflows/…` only after review (`enable` default false) |

## Review fixes (HOLD → addressed in this tip)

1. **Same-process migrate** — `agreed-fee-isolated-migrate.php` asserts env + refuses config-cache + asserts loaded DSN (url/socket/read/write) **before** Migrator DDL. Stack + workflow companion never call plain `php artisan migrate`.
2. **UI stale** — `fee-back` before editing `fee-charge` (removed under `v-if=!preview`); fixture success only on `changed`; waits exact preview/apply responses.
3. **state_changed** — valid preview → `__isolated/bump-tracked-state` → exact HTTP 409 `state_changed` with no side effects (not post-apply forged ≥400). Real browser cases: invoice 8800→7200 + regular 8800→7200.
4. **Deep snapshot** — preview no-write; original invoice 7600/status/items/dates; replacement unpaid/PaidAmount/id/DueDate; audit + notification; replay compares response body **and** DB snapshot fields (not counts alone).
5. **Origins / token / cleanup** — BASE/API/Vite proxy allowlist precise loopback (`127.0.0.1:5179` / `:8000`); seed token unexpired + course allowlist; env written under umask 077 → 0600; `EXTERNAL=1` writes `/tmp/agreed-fee-owned-cleanup.sh` and workflow traps it so Playwright failure cannot skip cleanup.
6. **Companion JUnit** — `--log-junit` + non-zero test assert + artifact upload; `AGREED_FEE_ISOLATED_REQUIRE=1` fail-closed; stack ready requires **both** API and Vite or exit 1.

## Dual-source bind

- **candidate_sha**: tip containing `AgreedFeeCorrection*` + modal (e.g. #4250 `a579…`).
- **harness_sha**: this draft tip under `docs/plans/agreed-fee-isolated-fullstack`.

## Non-claims

Inert under `docs/plans/` · no live workflow copy · no dispatch · seed contract not rewritten · #4250 untouched.
