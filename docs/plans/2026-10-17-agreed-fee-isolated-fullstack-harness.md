# Agreed-fee isolated full-stack harness (DRAFT — do not enable)

**Status:** review-only · inert under `docs/plans/` · **not** on #4250 · **not** dispatched · no live `.github/workflows/` file  
**Updated:** 2026-10-17T20:50Z  

## Verdict on existing CI

| Job | MySQL | Playwright | Real Laravel | Agreed-fee |
|---|---|---|---|---|
| `ci.yml` phpunit | Yes | No | in-process | Feature tests |
| `ci.yml` Vite | No | ui-foundation (mocked API) | No | No |
| `ui-smoke.yml` | No (production) | Yes | production | No — forbidden as candidate evidence |

Jobs are **disjoint**. Full-stack needs a **new dispatch-only** job (proposed YAML stays inert).

## Hard constraints (this draft)

1. **Guard before any DDL** — `db-guard.proposed.sh` then PHP `agreed_fee_isolated_assert_*` (refuse `DATABASE_URL` / `DB_SOCKET` / read-host; require `AllTrue_test` + loopback; reconnect after bootstrap so config-cache cannot retarget).
2. **APP_KEY** — router/seed set process-local key identical to `backend/phpunit.xml` (avoids `signing_unavailable` 503). Never edit `config/billing.php` (hard `false`).
3. **Same-origin** — modal `fetch('/api/...')` only; Vite proxies `/api` and `/__isolated` → `:8000`. `window.__AGREED_FEE_API__` is not used.
4. **Assertions** — preview no-write / apply / replay / stale / no receipt via `/__isolated/fee-snapshot` + response JSON (charge, corrections, payments).
5. **Dual SHA** — workflow inputs `candidate_sha` + `harness_sha`, env-bound, 40-hex validated before checkout/archive.
6. **Token hygiene** — env file mode `0600`; never cat/upload; scrub Bearer from logs; no token in navigation URL.
7. **Companion PHPUnit** — required filter before browser seed (RefreshDatabase must not wipe seed mid-flight).

## Artifact index

See [`agreed-fee-isolated-fullstack/README.md`](./agreed-fee-isolated-fullstack/README.md).

## Exact verification commands

```bash
# A) Already GREEN on #4250 a579 — keep
# run 39066292394 · JUnit 4146/20839 · AgreedFee 44/488a

# B) Companion (required; needs MySQL)
cd backend && vendor/bin/phpunit --filter \
  'AgreedFeeCorrectionTest|AgreedFeeConcurrencyTest|FeeReceiptKindCompatibilityTest'

# C) Proposed isolated full-stack (candidate tip + this harness draft)
export DB_DATABASE=AllTrue_test DB_HOST=127.0.0.1 DB_USERNAME=admin DB_PASSWORD=ci-ephemeral
unset DATABASE_URL DB_URL DB_SOCKET DB_READ_HOST DB_WRITE_HOST
export AGREED_FEE_STACK_EXTERNAL=1
bash docs/plans/agreed-fee-isolated-fullstack/agreed-fee-isolated-stack.proposed.sh
set -a; source /tmp/agreed-fee-isolated.env; set +a   # do not tee/cat into CI logs
cd frontend && npx playwright test -c playwright.agreed-fee-isolated.config.js
rm -f /tmp/agreed-fee-isolated.env                    # mandatory token cleanup

# D) Forbidden
# npm run test:e2e / SMOKE_* / flipping config/billing.php / live workflow without review
```

## Non-claims

Flag OFF in production · no merge/land · originals (#4247–#4251) untouched · #4252 frozen.
