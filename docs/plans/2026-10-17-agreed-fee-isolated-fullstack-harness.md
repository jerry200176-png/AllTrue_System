# Agreed-fee isolated full-stack harness (DRAFT — do not enable)

**Status:** review-only draft · **not** on #4250 candidate · **not** dispatched · **not** a merge/land proposal  
**Date:** 2026-10-17  
**Goal:** browser → real Laravel API → isolated `AllTrue_test` MySQL for synthetic agreed-fee cases (8000→500 / 8800 / 7200 / preview·apply·replay·stale / no receipt).  
**Non-goals:** production smoke, production flag flip, protected workflow settings, new platform/service, omitting PHPUnit core regressions.

---

## 1. Verdict on existing CI jobs

| Job (in `ci.yml`) | MySQL `AllTrue_test` | Playwright Chromium | Real Laravel HTTP | Agreed-fee path |
|---|---|---|---|---|
| `phpunit` | **Yes** (service + migrate) | No | In-process `$this->postJson` only | **Yes** — `AgreedFeeCorrectionTest` / Concurrency / FeeReceiptKind |
| `frontend` (Vite) | No | **Conditional** (`ui_foundation` path filter) | No (ui-foundation = **mocked API**) | No |
| `ui-smoke.yml` | No (hits **production**) | Yes | Production | **No** — read-nav smoke only; must not be candidate evidence |

**Conclusion:** file-level `ci.yml` contains both MySQL and Playwright install steps, but they live in **disjoint jobs**. There is **no** existing job that can today call “browser + Laravel serve + AllTrue_test” with one runner. Reusing pieces is possible; reusing a job as-is is **not**.

Do **not** bolt this onto the required `phpunit` / Vite jobs (timeout, FinOps, required-check surface). Do **not** use UI Smoke / `SMOKE_*` as candidate full-stack evidence.

---

## 2. Reuse inventory (no new platform)

| Piece | Reuse as |
|---|---|
| `ci.yml` phpunit `services.mysql` (`mysql:8.0`, `AllTrue_test`, `ci-ephemeral`) | Same service block in a **new** dispatch-only job (proposed, inert until reviewed) |
| `php artisan migrate --force` + Composer install steps | Copy from phpunit job |
| `AgreedFeeTestSchema::ensureExtensionColumns()` | Test-DB-only ALTER (already refuses production semantics) |
| `AgreedFeeFixture` / `feePayload()` | Seed director token + trial course Charge=8000 + unpaid invoice |
| `backend/tests/Support/agreed-fee-worker.php` pattern | Process-local `config(['billing.agreed_fee_correction_enabled' => true])` **only** when `DB_DATABASE===AllTrue_test` — never edit `config/billing.php` (hard `false`, non-env) |
| `npx playwright install --with-deps chromium` | Same command as Vite / ui-smoke |
| Playwright config style | Like `playwright.ui-foundation.config.js` / `billing-correction-guidance.spec.js` (explicit env gate + skip) |
| UI under test | Mount `AgreedFeeCorrectionModal` on an isolated fixture page (CourseManagement `agreedFeeCorrectionAvailable` is still hard `ref(false)` on tip — product entry wiring is **out of scope** for this harness) |

---

## 3. Minimal harness shape (test-only)

```
[Playwright Chromium]
    → http://127.0.0.1:5179/agreed-fee-isolated.html  (Vite fixture; real fetch)
         → http://127.0.0.1:8000/api/v1/.../agreed-fee-correction/{preview,apply}
              → Laravel (artisan serve) + process-local flag ON
                   → MySQL AllTrue_test (+ AgreedFeeTestSchema columns)
```

**Hard gates (fail closed):**

1. `AGREED_FEE_ISOLATED_BASE_URL` unset → Playwright **skip** (never green-by-skip in a required gate; only run when explicitly invoked).
2. Bootstrap refuses unless `DB_DATABASE=AllTrue_test` and host ∈ `{127.0.0.1, agreed-fee-test-mysql}`.
3. Flag enable **only** via in-process `config([...])` in the isolated serve bootstrap — **not** `env()`, **not** mutating `config/billing.php`, **not** production `.env`.
4. Companion command still runs PHPUnit fee filters — harness does **not** replace core regressions.

**Proposed new files** (see `docs/plans/agreed-fee-isolated-fullstack/`; none installed under `.github/workflows/`):

| Path | Role |
|---|---|
| `scripts/agreed-fee-isolated-stack.proposed.sh` | migrate → schema → seed → serve + vite + print URLs |
| `backend/tests/Support/agreed-fee-isolated-serve.proposed.php` | artisan-serve bootstrap with DB/host refuse + process-local flag |
| `frontend/playwright.agreed-fee-isolated.proposed.config.js` | dedicated config; ignore production smoke specs |
| `frontend/e2e/agreed-fee-isolated-fullstack.proposed.spec.js` | 8000→500 preview/apply against real API; no `page.route` mock for fee |
| `frontend/vite.agreed-fee-isolated.proposed.config.js` + fixture HTML/mount | Mount modal; Vite proxies `/api`→`:8000` |
| `docs/plans/agreed-fee-isolated-fullstack/proposed-workflow.yml` | **inert** copy — `workflow_dispatch` only + `inputs.enable` default `false` |

---

## 4. Workflow change (only if approved) — prerequisites

**Keep inert until review.** Do **not** push into ready #4250 (`a579`); that PR is non-draft and would auto-run PR workflows if a `pull_request` trigger were added.

Prerequisites before any live workflow file under `.github/workflows/`:

1. Independent review of this plan + proposed files.
2. New file is **`workflow_dispatch` only** (no `pull_request` / `push` / `schedule`).
3. Job `if: ${{ inputs.enable == 'true' }}` with input default `false`.
4. Explicit SHA pin in dispatch notes; Dot/operator verifies head before enable.
5. Artifact: Playwright report + JUnit from companion PHPUnit filter.
6. Still run (same job or prior step):

```bash
vendor/bin/phpunit --filter 'AgreedFeeCorrectionTest|AgreedFeeConcurrencyTest|FeeReceiptKindCompatibilityTest'
```

7. No production secrets (`SMOKE_*` forbidden in this job env).

---

## 5. Exact verification commands (local / CI-shaped)

### A) Already proven on candidate #4250 tip `a579` (keep)

```text
CI run 39066292394 — SUCCESS — exact a5790161f6c15a079f9a544a88914d481bf97d93
JUnit: 4146 tests / 20839 assertions
AgreedFee leaf: CorrectionTest 30 + ConcurrencyTest 12 + FeeReceiptKind 2 (=44 / 488a)
```

Re-filter (CI MySQL job only; do not re-run without MySQL locally):

```bash
cd backend
vendor/bin/phpunit --filter 'AgreedFeeCorrectionTest|AgreedFeeConcurrencyTest|FeeReceiptKindCompatibilityTest'
```

### B) Vitest UI (mock fetch — not full-stack)

```bash
cd frontend
npx vitest run src/components/__tests__/AgreedFeeCorrection.test.js \
  src/components/course-management/__tests__/ContractAdjustmentChoiceModal.test.js
```

### C) Proposed isolated full-stack (after files land + MySQL up; flag process-local only)

```bash
# 1) Core regressions first (required companion)
cd backend && vendor/bin/phpunit --filter \
  'AgreedFeeCorrectionTest|AgreedFeeConcurrencyTest|FeeReceiptKindCompatibilityTest'

# 2) Boot isolated stack (proposed script; refuses non-AllTrue_test)
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=AllTrue_test DB_USERNAME=admin DB_PASSWORD=ci-ephemeral
bash scripts/agreed-fee-isolated-stack.proposed.sh   # prints API=8000 UI=5179 TOKEN=…

# 3) Browser → real API (skip unless env set)
cd frontend
export AGREED_FEE_ISOLATED_BASE_URL=http://127.0.0.1:5179
export AGREED_FEE_ISOLATED_API_URL=http://127.0.0.1:8000
export AGREED_FEE_ISOLATED_TOKEN='<token from seed>'
export AGREED_FEE_ISOLATED_COURSE_ID='<id>'
export AGREED_FEE_ISOLATED_INVOICE_ID='<id>'
npx playwright test -c playwright.agreed-fee-isolated.proposed.config.js
```

### D) Explicit non-commands

```bash
# FORBIDDEN as candidate full-stack evidence
npm run test:e2e                    # production UI Smoke suite
# FORBIDDEN: flip config/billing.php or production .env
# FORBIDDEN: dispatch proposed workflow before review + inputs.enable=true
```

---

## 6. Relationship to #4250 / goal A–E

- Source-union tip `a579` + auto-CI GREEN stays the validation packet for API/DB synthetics.
- This harness is **additive evidence prep** only; does not land, enable flag, or close A/B/D/E.
- Original branches (#4247/#4248/#4249/#4251) untouched.
- #4252 remains frozen.
