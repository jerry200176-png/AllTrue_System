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
5. **Origins / token / cleanup** — BASE/API/Vite proxy allowlist precise loopback (`127.0.0.1:5179` / `:8000`); seed token unexpired + course allowlist; env written under umask 077 → 0600; `EXTERNAL=1` writes `/tmp/agreed-fee-owned-cleanup.sh`; workflow traps it before stack startup, tracking the actual Vite Node PID.

### 2026-10-18 review repair (still inert)

- Reuse merged #4254's minimal TCP configuration before providers bootstrap; refuse PDO options, alternate DSNs and non-loopback targets before Migrator, seed or router can connect.
- Count agreed-fee audit by student-class subject; each synthetic success adds exactly one row and replay adds none.
- These changes have syntax checks only. Full-stack execution, authoritative case evidence and activation remain pending; the proposed workflow is not installed or dispatched.
- Delivery must split from fresh main without stacking: environment/DDL lifecycle; fixture/router/seed; Playwright spec/config; proposed workflow. Keep every delivery PR within700 lines.
6. **Companion JUnit** — `--log-junit` + non-zero test assert + artifact upload; `AGREED_FEE_ISOLATED_REQUIRE=1` fail-closed; stack ready requires **both** API and Vite or exit 1.

Post-HOLD microfixes (tip after `72972d`): empty `read=>[]`/`write=>[]` accepted (non-empty still refused; harden drops empty stubs); state_changed uses modal `fee-apply` with sealed preview key (no fresh UUID); Vite proxy requires exact `:8000` origin.

## Dual-source bind

- **candidate_sha**: tip containing `AgreedFeeCorrection*` + modal (e.g. #4250 `a579…`).
- **harness_sha**: this draft tip under `docs/plans/agreed-fee-isolated-fullstack`.

## Non-claims

Inert under `docs/plans/` · no live workflow copy · no dispatch · seed contract not rewritten · #4250 untouched.
