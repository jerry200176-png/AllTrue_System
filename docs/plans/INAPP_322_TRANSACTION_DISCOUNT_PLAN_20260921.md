# In-App #322 — Transaction Discount at Course Creation

## Plan identity

- SourceRef: `alltrue:bug_report:322`
- GitHub issue: #3072
- Plan revision: P2.1
- Revision reason: P1 did not bind the finance gate at every canonical
  `UniversalClassScheduler` mount. P2 adds explicit fail-closed propagation for
  Student Management, Course Management, and Smart Calendar, including the
  teacher-accessible calendar path, and makes direct endpoint coverage explicit.
  P2.1 changes only delivery grouping: it binds the approved 15-file product/test
  boundary to exact linked A12/B10 deliveries under the repository's 12-file cap.
- Planner: Sol (`gpt-5.6-sol`)
- Evidence baseline: `3d86f37a87576fc9051b1b2d7c77f176cefad36d`
- Scope fingerprint: `P2.1-3d86f37-322-transaction-discount-linked-A12-B10`
- Status: P2.1 plan revision only; revising this document does not authorize
  production activation or historical financial mutation

## Evidence and canonical paths

- Canonical new-course UI is `frontend/src/components/UniversalClassScheduler.vue`.
- Canonical create endpoint is `POST /api/v1/class-sessions/batch`, handled by `ClassSessionController::batchStore()` and `EnrollmentService::store()`.
- `POST /api/v1/student-classes` is retired and returns 410; do not implement the feature in the legacy `StudentClassController::store()` path.
- Current amount authority persists integer `StudentClass.Charge`: session mode uses rounded price × sessions; hourly mode uses rounded price × hours. The frontend mirror is `frontend/src/lib/coursePricing.js`.
- Purchase/renewal create new transactions through `purchaseBatch()`, `renewMonthly()`, and `renewalConfirm()`.
- Monthly renewal also creates integer `Invoice.TotalAmount` and `InvoiceItem.Amount`; payments use integer `Payment.Amount`; refund/void behavior is represented by negative/void payments.
- Existing nullable integer `StudentClass.Disconunt` is misspelled, lacks reason/actor/time/type, and is not canonical charge calculation. It must not be reused or inherited.
- Existing JSON snapshot convention is `Invoice.billing_snapshot`; money storage is integer TWD. No reusable Money value object was found.
- Existing create/renew base-price calculation uses floats before integer rounding. This plan does not rewrite that authority; new discount arithmetic must consume the resulting integer original total and use integer/string arithmetic only.
- Existing course access permits owning teachers in some paths; discount authorization must be a separate fail-closed guard.
- A create request may create multiple course rows. Discount is calculated once on the transaction total, then allocated deterministically across rows.

## Product contract

The request contains one mutually exclusive object:

```text
discount.type   = NONE | FIXED_AMOUNT | PERCENTAGE
discount.value  = decimal string
discount.reason = free text
```

- `NONE`, `FIXED_AMOUNT`, and `PERCENTAGE` are mutually exclusive; no stacking.
- Discount applies to the original total for this course/purchase/contract transaction, not the per-session reference price, standard course price, teacher rate, or existing billing definition.
- Fixed amount: integer TWD, `0 < value <= original_amount`; full amount is allowed.
- Percentage: `0 < value <= 100`, at most two decimal places; values over 100, negative, malformed, or over-precision are rejected.
- Zero fixed or percentage input normalizes to `NONE` with value/discount zero and blank reason.
- Any nonzero discount requires a trimmed nonempty free-text reason.
- Backend recalculates and owns `original_amount`, `discount_amount`, and `final_amount`; clients must not submit or override those totals.
- Use existing Money convention; if no convention exists, use TWD integer HALF_UP at the total level once. Percentage input is converted to basis points; no new binary floating-point money arithmetic.
- Invariant: `original_amount - discount_amount = final_amount` and `NONE` means discount zero and final equals original.
- Persist an immutable pricing snapshot containing original amount, normalized type/value, discount amount, final amount, reason, actor, and timestamp, using existing snapshot/audit conventions.
- After creation there is no #322 historical snapshot edit. Future correction must use existing adjustment/void/refund flows; do not invent one.
- Existing contracts, invoices, payments, refunds, and void/negative-payment reconciliation must not be rewritten or backfilled.
- Renewal/purchase is a new transaction; default is `NONE`, and prior discount/snapshot is never inherited.
- Only existing financial-authorized roles (`director`, `admin`, `super_admin`) may set or view discount data. Teacher mutation must fail closed with 403 and teacher responses must not expose new pricing fields. Do not redesign RBAC.

## Architecture slice

1. Add a nullable JSON `pricing_snapshot` to `StudentClass`, with a migration that leaves existing rows NULL and performs no historical rewrite.
2. Add `TransactionDiscountCalculator` that parses decimal strings without floats, normalizes zero, validates reason/type/ranges, calculates one total-level HALF_UP discount, and emits the immutable snapshot.
3. In `EnrollmentService`, derive all newly-created rows' existing integer original `Charge`, sum once, calculate the transaction discount once, and allocate the resulting final total with deterministic integer largest-remainder allocation so row totals sum exactly to final. Store the same transaction snapshot and transaction identity on each new row as permitted by the existing schema; revise the plan before adding any extra schema outside the boundary.
4. Apply the calculator to `purchaseBatch()` and `renewMonthly()`. New course `Charge`, monthly `Invoice.TotalAmount`, and `InvoiceItem.Amount` must agree with the final amount. Renewal preview/state hashing includes normalized discount input. Never inherit legacy `Disconunt` or a previous snapshot.
5. Keep `pricing_snapshot` hidden by default. Add it only to director/admin/super_admin course responses; teacher response shape and pricing visibility remain unchanged.
6. Add a reusable discount section to canonical create and Student Management renewal/purchase UI: default NONE; show original, mode, value, reason, discount, and final before submit. Frontend preview is informational; backend remains authoritative.
7. `UniversalClassScheduler` owns a boolean `allowFinancialDiscount` prop whose
   default is `false`. Every mount must pass the gate explicitly: `App.vue`
   passes the existing director/admin/super-admin eligibility into Student and
   Course Management; `SmartCalendar.vue` derives the same eligibility from its
   existing `userRole`. Teacher Smart Calendar renders no discount controls and
   emits no discount keys. Package mode remains outside #322 and never exposes
   the discount section.
8. UI gating is defense in depth only. All mutation endpoints independently
   authorize and recalculate the discount so a handcrafted teacher request or a
   direct call cannot bypass the contract.

## Exact 15-file product/test boundary

No product or test file outside these 15 paths is authorized:

1. `backend/database/migrations/<timestamp>_add_pricing_snapshot_to_student_class.php`
2. `backend/app/Models/StudentClass.php`
3. `backend/app/Services/TransactionDiscountCalculator.php`
4. `backend/app/Http/Controllers/ClassSessionController.php`
5. `backend/app/Services/EnrollmentService.php`
6. `backend/app/Http/Controllers/StudentClassController.php` for active renewal/purchase validation, preview/hash forwarding, authorization, and role-filtered read projection only; never implement retired create there
7. `frontend/src/components/UniversalClassScheduler.vue`
8. `frontend/src/pages/StudentsList.vue`
9. `frontend/src/components/course-management/RenewMonthlyModal.vue`
10. `frontend/src/lib/coursePricing.js`
11. `backend/tests/Feature/StudentClassTransactionDiscountTest.php`
12. `frontend/src/components/__tests__/TransactionDiscount.test.js`
13. `frontend/src/App.vue`
14. `frontend/src/pages/SmartCalendar.vue`
15. `frontend/src/pages/CourseManagement.vue`

The Plan and session manifest are governance artifacts, not product/test files:

- `docs/plans/INAPP_322_TRANSACTION_DISCOUNT_PLAN_20260921.md`
- `.agent-session/manifest.json`

Release-note source and generated artifacts remain supporting delivery files;
their exact A/B placement is fixed below:

- `docs/CHANGELOG.md`
- `docs/STAFF_UPDATES.yml`
- `frontend/src/lib/changelogDraft.generated.js`
- `frontend/src/lib/staffUpdates.generated.js`

If runtime evidence proves another product/test file is necessary, stop and
revise this plan and its hash before editing. No coupon, promotion, approval,
commission, billing-engine, refund, invoice, migration-backfill, package-mode,
or global-RBAC work is authorized.

### Governance budget choice

The repository default is 12 changed files, so P2.1 does **not** silently raise
the manifest budget. Use these exact linked deliveries:

**Delivery A — exactly 12 files**

1. `backend/database/migrations/2026_09_21_000000_add_pricing_snapshot_to_student_class.php`
2. `backend/app/Models/StudentClass.php`
3. `backend/app/Services/TransactionDiscountCalculator.php`
4. `backend/app/Http/Controllers/ClassSessionController.php`
5. `backend/app/Services/EnrollmentService.php`
6. `backend/app/Http/Controllers/StudentClassController.php`
7. `backend/tests/Feature/StudentClassTransactionDiscountTest.php`
8. `frontend/src/components/UniversalClassScheduler.vue`
9. `frontend/src/lib/coursePricing.js`
10. `frontend/src/pages/StudentsList.vue`
11. `docs/CHANGELOG.md`
12. `docs/STAFF_UPDATES.yml`

Delivery A is integration-only. It must not deploy, trigger Phase-C writeback,
or claim shipped status; its UI is incomplete until B supplies every mount-level
finance gate and the generated release artifacts.

**Delivery B — exactly 10 files, based on the recorded exact A head**

1. `frontend/src/components/course-management/RenewMonthlyModal.vue`
2. `frontend/src/components/__tests__/TransactionDiscount.test.js`
3. `frontend/src/App.vue`
4. `frontend/src/pages/CourseManagement.vue`
5. `frontend/src/pages/SmartCalendar.vue`
6. `frontend/src/lib/changelogDraft.generated.js`
7. `frontend/src/lib/staffUpdates.generated.js`
8. `docs/plans/INAPP_322_TRANSACTION_DISCOUNT_PLAN_20260921.md`
9. `.agent-session/manifest.json`
10. `scripts/arch-contexts.json`

Do not pad B to 12. In particular, P2.1 does not authorize
`.github/workflows/bug-phase-c-allowlist.yml` or
`operations/closeout/bug-phase-c-allowlist.request.md`. Phase-C allowlisting and
writeback require deployed/runtime-verified evidence and a separate bounded
release action.

The integration owner records both exact heads. Neither delivery may claim
production readiness alone. B must consume the exact A head, and release review
waits for cross-delivery exact-head CI plus the full endpoint/UI matrix below.
If the operator instead wants one implementation delivery, stop and obtain an
explicit manifest budget of at least 15 product/test files before editing; P2.1
does not grant it.

## Required tests

Backend feature tests must cover NONE, fixed, percentage, two-decimal percentage, HALF_UP boundary, 100%, zero normalization, malformed/binary/scientific input, negative, fixed-over-original, percentage-over-100, over-precision, missing reason, forged totals, director/admin/super_admin success, teacher 403 and hidden snapshot, multi-subject allocation sum, purchase/renewal no-inherit, monthly Charge/Invoice/InvoiceItem equality, existing invoice/payment/refund invariants, immutable historical snapshot, and no-discount regression.

Endpoint integration coverage is mandatory for every callable authority:

- `POST /api/v1/class-sessions/batch`: canonical initial course creation.
- `POST /api/v1/student-classes/{id}/renewal-preview`: normalize the discount
  and bind it into preview/state hash without writing.
- `POST /api/v1/student-classes/{id}/renewal-confirm`: recalculate under lock and
  forward only normalized discount input.
- Direct `purchase-batch` and `renew-monthly`: enforce the same validation,
  authorization, calculation, and no-inheritance rules even when callers bypass
  `renewal-confirm`.
- `convert-trial`: adds no discount capability in P2, but must regress that the
  internally created purchase defaults to NONE and never inherits source state.

Frontend tests must cover default NONE, mutual exclusion, all preview fields, fixed/percentage/100%/rounding previews, reason required, renewal reset/no inheritance, and no calculated total fields submitted by the client. They must also inspect all three canonical scheduler mounts: Student Management and Course Management receive the `App.vue` finance gate; Smart Calendar enables it only for director/admin/super_admin; teacher and omitted/default prop paths render no discount UI and emit no discount payload. Preserve existing scheduler, role, and teacher-navigation assertions.

Run focused PHPUnit/Vitest first, then canonical lint/build/full suites through `local-heavy-gate`. CI must preserve assertions and existing allowlists.

## Stop conditions

Stop before implementation or merge if production MySQL cannot safely add the nullable JSON column without unacceptable rewrite/lock; if canonical create cannot stage all original charges before persistence; if any existing financial record would be rewritten; if the exact financial-role mapping cannot be proven; if refund, commission, entitlement, or payment truth would change; if snapshot can be mass-assignment modified; if required migration/deploy evidence is missing; or if production activation is requested without explicit approval.

Open operational evidence: production MySQL version, `student_classes` table size, and online-DDL behavior are not available from repository evidence. This is a deployment-time blocker, not permission to guess or use a destructive migration.

## Delivery boundary

The agent may investigate, implement, test, open a PR, and complete CI/review within this bounded scope. `CODE WRITTEN`, `TESTS PASSED`, `PR OPENED`, `REVIEWED`, `MERGED`, `DEPLOYED`, `RUNTIME ENABLED`, and `PRODUCTION VERIFIED` must remain separate. No In-App/GitHub resolved writeback occurs until evidence covers the approved SourceRef.
