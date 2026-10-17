# Agreed-fee isolated full-stack harness (DRAFT — do not enable)

**Status:** review-only · inert under `docs/plans/` · **not** on #4250 · **not** dispatched · no live `.github/workflows/` file  
**Updated:** 2026-10-17T21:00Z  

## Verdict on existing CI

| Job | MySQL | Playwright | Real Laravel | Agreed-fee |
|---|---|---|---|---|
| `ci.yml` phpunit | Yes | No | in-process | Feature tests |
| `ci.yml` Vite | No | ui-foundation (mocked API) | No | No |
| `ui-smoke.yml` | No (production) | Yes | production | No — forbidden as candidate evidence |

Jobs are **disjoint**. Full-stack needs a **new dispatch-only** job (proposed YAML stays inert).

## Hard constraints (this draft)

1. **Same-process migrate before DDL** — shell env guard + `agreed-fee-isolated-migrate.php` (refuse `.env`/config-cache/`DATABASE_URL`/socket/read/write redirects after bootstrap) → Migrator only then. Plain `php artisan migrate` forbidden in stack/workflow. Post-hoc PHP guards do not remediate a wrong DDL target.
2. **APP_KEY** — router/seed set process-local key identical to `backend/phpunit.xml` (avoids `signing_unavailable` 503). Never edit `config/billing.php` (hard `false`).
3. **Same-origin** — modal `fetch('/api/...')` only; Vite proxies `/api` and `/__isolated` → loopback `:8000` (allowlist, not lifenet blacklist).
4. **Assertions** — preview no-write / apply / replay body+DB / fee-back stale / state_changed 409 / 8800→7200 cases via `/__isolated/fee-snapshot`.
5. **Dual SHA** — workflow inputs `candidate_sha` + `harness_sha`, env-bound, 40-hex validated before checkout/archive.
6. **Token hygiene** — umask 077 → env mode `0600`; never cat/upload; scrub Bearer; no token in navigation URL; unexpired token bound to seeded course allowlist.
7. **Companion PHPUnit** — guarded migrate + `--log-junit` + non-zero tests required + artifact upload before browser seed.
8. **Ready / REQUIRE** — API **and** Vite must be ready or fail; `AGREED_FEE_ISOLATED_REQUIRE=1` fail-closed (no skip); `EXTERNAL=1` traps owned cleanup on Playwright failure.

## Artifact index

See [`agreed-fee-isolated-fullstack/README.md`](./agreed-fee-isolated-fullstack/README.md).

## Exact verification commands

```bash
# A) Already GREEN on #4250 a579 — keep
# run 39066292394 · JUnit 4146/20839 · AgreedFee 44/488a

# B) Companion (required; needs MySQL) — after installing migrate.php from this draft
cd backend && php tests/Support/agreed-fee-isolated-migrate.php
cd backend && vendor/bin/phpunit --filter \
  'AgreedFeeCorrectionTest|AgreedFeeConcurrencyTest|FeeReceiptKindCompatibilityTest' \
  --log-junit test-results/agreed-fee-companion-junit.xml

# C) Proposed isolated full-stack (candidate tip + this harness draft)
export DB_DATABASE=AllTrue_test DB_HOST=127.0.0.1 DB_USERNAME=admin DB_PASSWORD=ci-ephemeral
unset DATABASE_URL DB_URL DB_SOCKET DB_READ_HOST DB_WRITE_HOST
export AGREED_FEE_STACK_EXTERNAL=1 AGREED_FEE_ISOLATED_REQUIRE=1
bash docs/plans/agreed-fee-isolated-fullstack/agreed-fee-isolated-stack.proposed.sh
trap 'bash /tmp/agreed-fee-owned-cleanup.sh || true' EXIT
set -a; source /tmp/agreed-fee-isolated.env; set +a   # do not tee/cat into CI logs
cd frontend && npx playwright test -c playwright.agreed-fee-isolated.config.js

# D) Forbidden
# npm run test:e2e / SMOKE_* / flipping config/billing.php / live workflow without review
```

## Non-claims

Flag OFF in production · no merge/land · originals (#4247–#4251) untouched · #4252 frozen · **no workflow enable/dispatch in this pass**.
