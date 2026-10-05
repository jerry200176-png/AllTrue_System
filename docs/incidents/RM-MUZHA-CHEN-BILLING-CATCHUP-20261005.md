# Repair Manifest — 木柵 宥翰 補收帳單 + 作廢孤兒帳單 1053（2026-10-05）

Status: POP operation `muzha-chen-billing-catchup-20261005`; production execution requires a successful POP dry-run and authenticated database approval.
Risk: R3/T3. Founder decisions 2026-10-05 (two parts, one transaction).

## Purpose

A) Student 164 (CampusID 16), contract 1249 (SubjectID 66, TeacherID 29, ScheduleMode date, Stop=1, Paid=1; its only invoice is 422 for April)
attended lessons in June (3), July (3) and August (1) 2026 that no invoice covers. The Founder chose to bill them at NT$1,650 per lesson:
2026-06 NT$4,950, 2026-07 NT$4,950, 2026-08 NT$1,650 (total NT$11,550).

B) Student 162 (CampusID 16) has orphan invoice 1053 (StudentClassID 2564, which no longer exists; billing_period 2026-07; NT$6,000; unpaid).
No lessons, payments or payment reports exist. It is voided.

## Scope

Exact ids only, in `backend/app/Operations/Strategies/MuzhaChenBillingCatchupStrategy.php`.
Parameters: `decision_reference = repair-muzha-chen-billing-catchup-20261005`.
No ClassSession, sign-in, learning record or contract 1249 is moved or modified. Eloquent creates are used so the Invoice/InvoiceItem/StudentClass hooks run.

## Preconditions (checked by plan and again under lock; one error code each)

- 1249: `source_contract_missing`, `source_contract_student` (164), `source_contract_campus` (16), `source_contract_not_date_mode`, `source_contract_tutoring`.
- Billable lessons on 1249 (attended/completed/late, as `MonthlyBillingService::BILLABLE_STATUSES`) from 2026-06-01 on are exactly 3 in June, 3 in July, 1 in August (`lessons_mismatch`), the August one on or before 2026-08-14 (`august_lesson_after_cutoff`).
- No non-void invoice of any of student 164's contracts covers those dates (`lesson_already_invoiced_<YYYY-MM>`). Coverage: an item owned by that contract (null item owner = the invoice's anchor contract) whose PeriodStart and PeriodEnd both contain the date; or, for an invoice with no such period-bearing items, `billing_period` equal to the lesson month.
- No catch-up contract exists yet (`catchup_exists`; found by Memo containing the decision_reference; this is also the after-state).
- 1053: `orphan_invoice_missing`, `_student` (162), `_not_unpaid`, `_paid_amount` (0), `_has_payments`, `_has_reports` (payment_reports on the invoice or on 2564), `_total` (6000), `_contract_id` (2564), `orphan_contract_exists`.
- Information only, not an error: `info.may_billable_lessons` and `info.may_covered` report the May 2026 billable lessons on 1249 and whether invoices cover them. May is not billed by this operation.

## Dry-run

Expect `ok=true`, `state=before`. `state=after` means already applied.

## Approval

Founder `founder-go-*` reference, super_admin approver, bound to the deployed SHA. Policy `founder-exact-muzha-chen-billing-catchup`.

## Execute

Pi-local POP only. One transaction: lock 1249, student 164's contracts and their invoices, and 1053; re-run the checks; create ONE StudentClass
(copy of 1249's SubjectID/TeacherID/GradeID and other required columns; ScheduleMode date, 2026-06-01 to 2026-08-31, Charge 11550, Paid 0, Stop 1,
`closed_reason` settled_pending, SessionCount 0, Memo naming 1249 and the decision_reference), three Invoices (billing_period 2026-06/07/08,
NT$4,950/4,950/1,650, unpaid, Note = decision_reference) each with one InvoiceItem (Amount equal to the invoice, month first/last day), and set
1053 to `void` with the decision_reference appended to Note. Created counts and the affected row count are asserted; a strict
`pop.muzha_chen_billing_catchup` SecurityAuditEvent (correlation id re-read) is written in the same transaction. The snapshot records the created ids and 1053's old Status/Note.

## Verify

One catch-up contract with the three invoices (right periods and totals) and one item each; 1053 is `void` and its Note carries the decision_reference.

## Rollback

Uses the stored snapshot. Deletes a created invoice (and its item) only while it is still `unpaid`, PaidAmount 0, with no payments or reports and its original total;
deletes the contract only when it is untouched (Paid 0, same Charge/state, no remaining invoices/items/sessions/reports). Restores 1053 only while it is still
`void` with exactly the appended Note and no payments/reports. Anything changed since is skipped and listed in `skipped_ids`, and the result is
`ok=false, partial=true`. As with the other POP repairs there is no production adapter that runs `rollback` yet: a rollback is a new Founder-approved POP request built from the stored snapshot.

## Stop conditions

Dry-run not ok or any drift code; approval or SHA mismatch; verify failure. Do not retry blindly: re-run a read-only probe.
Attach the POP request id, sanitized dry-run/execute/verify records and the deployed SHA to closeout.

## Dispatch adapter

`.github/workflows/pop-muzha-chen-billing-catchup.yml` (production-activation environment). `mode=dry-run` with
`DRY_RUN_MUZHA_CHEN_BILLING_CATCHUP_20261005` drafts the request and prints the dry-run result; `mode=approve` with
`APPROVE_MUZHA_CHEN_BILLING_CATCHUP_20261005` approves with `founder-go-muzha-chen-billing-catchup-20261005` bound to the
deployed backend SHA, then observes the Pi-local execute + verify. Both require `deployed_backend_sha` equal to `deployment.json`.
