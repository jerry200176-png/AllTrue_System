# Repair Manifest — 未繳卻被結案隱藏的合約 2026-10-05

Status: POP operation `unpaid-hidden-closures-20261005`; production execution requires a successful POP dry-run and authenticated database approval.
Risk: R3/T3. Founder decision 2026-10-05: put every such contract into 帳務中心「待對帳」.

## Purpose

Month renewals and monthly pauses used to write `closed_reason = settled`/`completed` without a payment check
(fixed in #3529). Those unpaid contracts (StudentClass Stop=1) vanished from 帳務中心 and tuition alerts. The
repair flips only `closed_reason` to `settled_pending` so they re-enter the reconciliation queue.
Invoice, Payment, Charge and Paid are never written.

## Scope

The closed allowlist `UnpaidHiddenClosuresManifest::cases()` in
`backend/app/Operations/Strategies/UnpaidHiddenClosuresManifest.php`
(`student_class_id => closed_reason, outstanding, campus_id`), filled from read-only probe run `<id>`.
Strategy: `UnpaidHiddenClosuresStrategy`. Parameters: `decision_reference = repair-unpaid-hidden-closures-20261005`.
Tutoring (`LOWER(TRIM(ClassType))`) is out of scope. Paid=1 rows with open invoices are not candidates; they need a reviewed correction.

## Preconditions (checked by plan and again under lock)

Per manifest row: still Stop=1, same `closed_reason`, Paid != 1, not tutoring, student campus matches, and the ledger
(`InvoiceAmountReconciliationService::resolve`; legacy `PaidAmount` when an invoice has no payment rows; `Charge` when
there are no invoices) still owes exactly the manifest `outstanding`. Any drift returns one error code per course id and aborts.
Pending payment reports are not excluded: the course and invoice row locks serialize against
`PaymentReportController::confirm`, and a later rejected report simply leaves the course visible in `settled_pending`.

## Dry-run

Create a draft and run the dry-run through the POP API. Expect `ok=true`, `state=before`, and the snapshot
(per-row reason, outstanding, invoice ids, payment-row count). `state=after` means already applied.

## Approval

Founder `founder-go-*` reference, super_admin approver, bound to the deployed SHA. Policy `founder-exact-unpaid-hidden-closures`.

## Execute

Pi-local POP only. One transaction: lock the StudentClass rows and their Invoice rows, re-run the checks, update
`closed_reason` to `settled_pending` for exactly the manifest ids (affected count asserted), append a
`pop.unpaid_hidden_closures` SecurityAuditEvent.

## Verify

Every manifest row is `settled_pending`, or has legitimately moved on (ledger shows zero outstanding, or
`closed_reason` in `contract_amended`, `waived`). Any unpaid row in `settled`/`completed`/null fails. Also spot check
帳務中心「待對帳」lists the courses.

## Rollback

Uses the stored snapshot. Restores the old reason only for rows still `settled_pending` whose ledger is unchanged
(same outstanding and payment-row count). Rows with later payments or other changes are skipped and reported in
`skipped_ids`, and the result is `ok=false, partial=true` so it is never reported as a complete rollback.

POP currently has no production adapter that runs a strategy's `rollback` (the API only drafts, dry-runs and
approves; `pop:execute` runs execute + verify). A rollback is therefore a new Founder-approved POP request built from
the stored snapshot, not an extra button; until that adapter exists, treat this repair as forward-fix only.
Reverting code does not reverse committed data.

## Stop conditions

Dry-run not ok or any drift code; manifest row count differs from the approved probe; approval or SHA mismatch;
verify failure; any sign money fields changed. Do not retry blindly: re-run a read-only probe and get a new manifest.

Attach the POP request id, sanitized dry-run/execute/verify records and the deployed SHA to closeout.
