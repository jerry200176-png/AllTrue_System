# Scheduling and billing evidence review (2026-08-12)

## Decision and scope

This post-release review checks whether the fixes shipped in `f92f280bd67262b4c0134abd96883ea49526c57f` for in-app bugs #228-#233 establish durable domain invariants or only repair the reported examples. The scope is pooled package capacity, schedule-to-session projection consistency, count-course charge calculation, and receipt service periods.

The repository's current business rules and production evidence remain authoritative. External systems are used to test the design, not to copy their UI or infer their private architecture.

## Evidence labels

- **Locally verified**: AllTrue source, tests, CI, deployment identity, or production readback.
- **Documented**: current official product/API documentation.
- **Observed**: public rendered behavior captured without authentication.
- **Source-code verified**: pinned open-source source and tests.
- **Inferred**: a proposed consequence that still needs local domain-owner confirmation.

## Current AllTrue behavior and constraints

### Pooled package capacity

**Locally verified.** `ManualSessionBookingService` now obtains remaining capacity from `CoursePackage::computeRemainingFromLedger()`, counts future reservations across all package member courses, locks the package row before the final check/create transaction, and keeps the booking operation idempotent. `ManualSessionBookingTest` covers pooled capacity and member-course reservations.

Conclusion: the #228/#229 fix is at the correct aggregate boundary and is not merely a UI workaround. The remaining architectural risk is that per-course `RemainingSessions` still exists beside the package ledger and can be read by other paths. New package-capacity decisions must use one package-domain service rather than either snapshot opportunistically.

### Schedule projection

**Locally verified.** `ClassSessionController::autoMaterializeScheduledExceptionsForRange()` repairs a missing `ClassSession` from a normal same-day `Schedule` during a read. It excludes `type=extra`, stopped courses, and cross-date reschedules, and delegates to idempotent `ClassSessionMaterializationService::upsertSlot()`. `ClassSessionsTeacherAutoMaterializeCountTest` covers the missing projection, cross-date exclusion, extra-row exclusion, and repeat reads.

Conclusion: this is safe containment for #233, but it is still a read-side mutation and therefore a symptom repair rather than the final consistency boundary. It only heals queries with a same-day range and cannot prove that all writers produce both records atomically.

### Count-course amount and receipt period

**Locally verified.** Tuition alerts now calculate a count-course amount from `Rate` and `SessionCount` (or hours for an hourly rate) instead of trusting a stale legacy `Charge`. Receipt dates are derived from the same session rows printed on the receipt. `TuitionAlertsApiTest` covers the 8 x 1,650 = 13,200 case; `PaymentReportApiTest` covers the period derived from actual receipt sessions.

Conclusion: both fixes select better present-time sources and correctly address #230/#232. They do not yet make issued financial documents immutable: the alert calculator and legacy `Charge` are still parallel amount sources, while a historical receipt can in principle change if its underlying sessions are edited later.

## Official and public-product evidence

1. **Documented — Stripe.** An invoice line item has its own inclusive `period.start` and `period.end`; subscription items populate those periods, and standalone invoice items should explicitly set them. This supports deriving or storing a service period at the billed-line level, not borrowing a later course/contract period. Sources: [Invoice Line Item API](https://docs.stripe.com/api/invoice-line-item/object), [Revenue Recognition with subscriptions and invoicing](https://docs.stripe.com/revenue-recognition/methodology/subscriptions-and-invoicing).
2. **Documented — Stripe.** Multiple products can share one subscription/invoice while quantities remain item attributes. This is analogous to one package aggregate with member subjects, not independent subject balances. Source: [Set product or subscription quantities](https://docs.stripe.com/billing/subscriptions/quantities).
3. **Documented — Lago.** A wallet is the balance-owning object; credits are applied to eligible fees in priority order, and finalized balance is distinguished from ongoing estimated balance. This supports one authoritative package ledger plus an explicitly labelled reservation projection. Source: [Wallet and prepaid credits overview](https://getlago.com/docs/guide/wallet-and-prepaid-credits/overview).
4. **Documented — Cal.com.** User booking limits aggregate accepted bookings across all event types, whereas event-type limits remain separate and are independently enforced. Slot reservation is an explicit temporary state. This supports enforcing package-wide capacity separately from course/slot capacity. Sources: [User booking limits](https://cal.com/docs/api-reference/v2/user-booking-limits), [Reserve a slot](https://cal.com/docs/api-reference/v2/slots/reserve-a-slot).
5. **Observed — Cal.com public booking page.** On 2026-08-12, unauthenticated Browser Run rendered `https://cal.com/peer`, followed its public redirect to `https://i.cal.com/peer`, and exposed separate 15-minute event types before a slot was selected. This confirms public separation of event type and concrete booking flow; it does not reveal Cal.com's internal persistence model.

## Open-source implementation evidence

No source code is copied. The licenses are recorded to avoid accidental transplantation.

### Cal.com

- Repository: <https://github.com/calcom/cal.com>
- Commit inspected: `176037d0afbe572f870a3c702985e7cd83fe6c0c`
- License: MIT in the inspected checkout; repository also contains separately governed commercial areas, so file-level licensing must be checked before reuse.
- Maintenance signal: commit dated 2026-08-08 and repository push observed on 2026-08-08.
- **Source-code verified:** `apps/api/v2/src/modules/booking-seat/booking-seat.repository.ts` models a seat with a unique reference and parent booking; `apps/api/v2/src/platform/bookings/2024-08-13/controllers/e2e/seated-bookings.e2e-spec.ts` creates a five-seat event and tests booking behavior through the API.
- Adaptation: a concrete reservation/attendance record should consume capacity under one parent aggregate. AllTrue's package row lock plus package-wide reservation count follows this principle.

### Lago

- Repository: <https://github.com/getlago/lago> with API submodule <https://github.com/getlago/lago-api>
- Commits inspected: shell `330f78f1716a2057b036032b6dd23b208f2cb8d8`; API `71680d30cf695c86c59510dbb201a12307fe31be`
- License: AGPL-3.0.
- Maintenance signal: API commit dated 2026-07-27; repository push observed on 2026-08-11; public repository reports frequent releases.
- **Source-code verified:** `app/services/wallets/balance/decrease_service.rb` updates authoritative balance and consumed totals together and schedules refresh/alerts after commit. `app/services/credits/allocate_prepaid_credits_by_wallets_service.rb` allocates from ordered wallets while capping by fee, wallet, and invoice remainder. `spec/services/wallets/balance/decrease_service_spec.rb` tests stale-object reload, balance/consumption changes, rounding, and after-commit work.
- Adaptation: centralize AllTrue package debits/reservations in one service, update the ledger transactionally, and refresh derived member snapshots after commit. Do not transplant AGPL code.

### Frappe Education

- Repository: <https://github.com/frappe/education>
- Commit inspected: `71aada478bf682f6d034fd4caa6f2f5438b5ace9`
- License: GPL-3.0.
- Maintenance signal: commit dated 2026-06-05 and repository push observed on 2026-08-10.
- **Source-code verified:** `education/education/doctype/course_schedule/course_schedule.py` validates academic bounds and overlaps for student group, instructor, room, and assessment plan before save. `test_course_schedule.py` tests each conflict and a valid non-conflicting schedule. `fee_schedule/fee_schedule.py` computes totals from fee components and student groups and prevents schedules exceeding the parent fee structure.
- Adaptation: enforce schedule invariants at write time and validate child financial allocations against the parent contract. Its term/group model is not a direct replacement for AllTrue's per-student package ledger.

## Pattern comparison and failure modes

| Area | Shared principle | Current AllTrue fit | Remaining failure mode |
| --- | --- | --- | --- |
| Package capacity | Parent aggregate owns the balance; reservations are explicit children | Correct aggregate calculation and package row lock | Other endpoints may still read per-course snapshots; concurrent non-manual writers may bypass the lock/service |
| Schedule projection | Validate conflicts before commit; concrete booking/session has stable identity | Idempotent upsert and careful exclusions | GET mutates state; missing write-path coverage can remain hidden until someone opens that day |
| Charge | Amount is computed from immutable priced line/quantity inputs | Alert now uses rate x purchased units | Legacy `Charge`, rate fields, and later edits can disagree; no single priced-line snapshot |
| Receipt period | Period belongs to the billed line and should survive later contract changes | Uses the actual displayed sessions | Recomputing from mutable sessions can rewrite historical meaning; malformed/removed sessions need a recovery rule |

## Recommended AllTrue adaptation

### 1. Package aggregate service

- Make one server-side `PackageCapacityService` the only API for available, reserved, consumed, and remaining units.
- Define states explicitly: available -> reserved -> consumed, with cancelled/expired reservation returning capacity.
- Require all booking paths (manual, recurrence, reschedule, import, admin repair) to lock or atomically compare-and-update the same package aggregate.
- Keep per-course remaining values as labelled projections only; add a drift metric and a reconciliation command before removing legacy reads.
- Preserve actor, source operation, idempotency key, package ID, member course ID, units, and before/after balance in the audit trail.

### 2. Durable schedule projection

- Keep the current read repair temporarily as a guarded safety net with a counter/log.
- Move authoritative repair to write time: schedule mutation and a projection outbox row in one database transaction, followed by an idempotent worker that upserts `ClassSession` using a stable occurrence key.
- Add a periodic read-only invariant checker comparing eligible `Schedule` rows to `ClassSession`; alert on drift and offer an explicit audited repair command.
- Preserve the existing business exclusions for `type=extra` and cross-date reschedules. Domain owner must confirm whether these remain schedule-only forever.

### 3. Financial snapshots

- At invoice/receipt issuance, persist line quantity, unit, unit rate, amount, included session IDs, and service-period start/end. Render historical documents from this snapshot.
- Use one pricing calculator to create the snapshot. Treat `StudentClass.Charge` as legacy/audit input, not a competing effective amount.
- Adjustments should create a new revision, credit note, or replacement document; never silently rewrite an issued receipt.
- Restrict financial snapshot creation and correction to the existing director/accounting permissions and audit every revision.

## Smallest safe experiments

1. **Package invariant test:** create two member courses sharing one package, race two attempts for the final unit through different booking paths, and assert exactly one succeeds and ledger remaining never becomes negative.
2. **Projection contract test:** enumerate every schedule writer and assert it emits the same stable occurrence key; simulate worker retry and out-of-order delivery; assert one `ClassSession` and no GET-side repair counter increment.
3. **Receipt immutability test:** issue a receipt, then reschedule/cancel a source session; assert the issued receipt's amount and period remain unchanged and an adjustment path is required.
4. **Shadow telemetry:** before changing behavior, record package snapshot drift, projection repair count, projection lag, and receipt live-vs-snapshot differences for one release. No private student data should be included in metrics.

Acceptance requires local unit/feature tests, authorization tests, concurrency tests, full CI, deployment identity, production health/smoke, and a read-only invariant report. Rollback keeps current calculators and read repair behind flags while retaining newly written audit/snapshot data.

## Risks and unknowns

- **Business decision required:** whether a paid package reservation consumes capacity immediately or only after attendance; this changes state transitions and available-balance wording.
- **Business decision required:** whether an issued receipt is a legally immutable record or a live course statement. The recommendation assumes immutability.
- **Operational:** an outbox worker needs lag/error monitoring and replay controls.
- **Privacy:** logs and telemetry must use internal IDs/counts, not student names or receipt details.
- **Licensing:** Cal.com MIT patterns are generally permissive, while Lago AGPL and Frappe GPL code must not be copied into AllTrue without a license review. This review adopts principles only.
- **Vendor lock-in:** no external billing/scheduling service is recommended; local contracts remain authoritative.

## Final assessment

- #228/#229 pooled-capacity fix: **structurally correct for the manual booking path**, with a follow-up needed to eliminate bypasses and duplicate balance sources.
- #233 schedule projection fix: **safe production containment, but still symptom-level architecture**; prioritize write-time outbox/idempotent projection plus drift monitoring.
- #230 amount fix: **correct calculation for the affected alert**, but consolidate pricing and snapshot it when financial documents are issued.
- #232 receipt-period fix: **correct source selection for current receipts**, but persist an immutable issued-line period to protect history.
