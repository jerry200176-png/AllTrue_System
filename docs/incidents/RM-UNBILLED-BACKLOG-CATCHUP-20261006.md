# Repair Manifest — 補收「合約結束後已上課、未開單」堂數（2026-10-06）

Status: POP operation `unbilled-backlog-catchup-20261006` (catalog v9); nothing runs until a Founder approves a specific dry-run.
Risk: R3/T3. Generalises `RM-MUZHA-CHEN-BILLING-CATCHUP-20261005` (same repair, computed instead of hard-coded).

## Purpose

Monthly (`ScheduleMode=date`) contracts whose attended lessons (attended/completed/late) fall after `EndDate` (or before `StartDate`) with no
invoice covering them: the lessons were taught but nobody renewed or billed. `MonthlyRenewalPeriodService::inspect` reports them as
`monthly_completed_sessions_outside_contract`; `GET accounting/monthly-drafts` lists them as `blocked`. Read-only prod scan (2026-10-06):
about 30 contracts / 127 lessons (campus 9: 12/54, 16: 13/58, 15: 5/15).

## Plan (computed at dry-run, ids only, no names)

Parameters: `campus_ids` (required, e.g. `[9]`; run campus by campus), `decision_reference = repair-unbilled-backlog-catchup-20261006`,
`expected_digest` (empty on the dry-run, the dry-run's `digest` on the approved run). One row per contract and month:
contract/student/campus ids, month, billed period, session ids, lesson count, amount, `status`.
- `ready`: amount = sum of `StudentClassPricingService::forDate` rate per lesson (the pricing `MonthlyBillingService` uses).
- `needs_director_amount`: a lesson has no per-session rate. Reported, never executed; the director supplies the amount.
- `skipped` + `reason`: `package`, `tutoring_or_trial`, `waived`, `contract_closed` (Stop=1 and not `settled_pending`), `already_billed`,
  `partially_billed`, `lessons_on_both_sides` (needs a human).
Covered = a non-void invoice item owned by the contract (or its earlier catch-up) whose period holds the lesson date, or a period-less invoice with that `billing_period`.

## Execute / attachment (as in Muzha)

Per `ready` row, in one transaction: a catch-up StudentClass cloned from the source (period = the uncovered part of the month, Charge = amount,
Paid 0, Stop 1, `settled_pending`, no sessions, Memo `[src:<id>]` + reference), one unpaid Invoice (`billing_period` = month) and one InvoiceItem.
Lessons are NOT re-pointed: source contract, sessions, sign-ins and learning records are untouched; a catch-up contract per month because
`MonthlyBillingService::summarizePeriod` returns a no-session contract's whole Charge. The digest is recomputed under row locks; any drift
(`digest_mismatch` / `unbilled_backlog_digest_drift`) aborts. Strict `pop.unbilled_backlog_catchup` audit. Rerun creates nothing (`state=after`).

## Flow

1. Dry-run per campus (`expected_digest` empty). Review totals and rows.
2. Founder reviews each campus's dry-run (especially `needs_director_amount` and skipped rows), then approves exactly that digest
   (`founder-exact-unbilled-backlog-catchup`, super_admin, bound to the deployed SHA).
3. Execute (Pi-local), then verify: every created contract/invoice/item matches the snapshot and no executed month is still uncovered.
4. Closeout: POP request id, sanitized records, deployed SHA; then a staff update card.

## Rollback

From the stored snapshot: delete each created invoice/item/contract only while it still equals what execute wrote (unpaid, no payments/reports,
same amount and period, contract has no sessions). Changed rows are skipped and reported (`ok=false, partial=true`). Production has no rollback
adapter yet: a rollback is a new Founder-approved POP request. A retry after an execution record failed to persist does not rebuild the snapshot.

## Dispatch adapter (follow-up, not in this PR)

Copy `.github/workflows/pop-muzha-chen-billing-catchup.yml` (plus its `PRODUCTION_WORKFLOW_INVENTORY.json` and `pii-log-workflows.txt`
entries) via the coordinator, adding the `campus_ids` and `expected_digest` inputs.
