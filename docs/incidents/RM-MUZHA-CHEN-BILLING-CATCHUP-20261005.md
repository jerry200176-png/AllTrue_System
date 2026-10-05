# Repair Manifest — 木柵 宥翰 補收帳單 + 作廢孤兒帳單 1053（2026-10-05）

Status: POP operation `muzha-chen-billing-catchup-20261005`; production execution requires a successful POP dry-run and authenticated database approval.
Risk: R3/T3. Founder decisions 2026-10-05 (two parts, one transaction).

## Purpose

A) Student 164 (CampusID 16), contract 1249 (math, ScheduleMode date, Stop=1, Paid=1; only invoice 422 = April) attended lessons in
June (3), July (3) and August (1) 2026 that no invoice covers. Founder: bill at NT$1,650/lesson → 06 NT$4,950, 07 NT$4,950, 08 NT$1,650.

B) Student 162 (CampusID 16): orphan invoice 1053 (StudentClassID 2564, deleted; 2026-07; NT$6,000; unpaid). No lessons, payments or
reports exist (probe run 37320161845). Founder: void it, do not bill.

## Scope and preconditions

Exact ids only (`MuzhaChenBillingCatchupStrategy`), parameter `decision_reference = repair-muzha-chen-billing-catchup-20261005`.
No ClassSession, sign-in, learning record or contract 1249 is modified. `plan` (and again under lock) fails with one error code per
drift: 1249 identity/campus/mode; billable lessons (`MonthlyBillingService::BILLABLE_STATUSES`) from 2026-06-01 exactly 3/3/1 with
August ≤ 2026-08-14; no non-void invoice of student 164 already covering those dates; no catch-up contract yet (= after-state);
1053 still unpaid, PaidAmount 0, no payments/reports, total 6000, contract 2564 absent. May 2026 is reported as `info` only.

## Dry-run → Approval → Execute → Verify

Dry-run: expect `ok=true, state=before`. Approval: `founder-go-*`, super_admin, bound to the deployed SHA (policy
`founder-exact-muzha-chen-billing-catchup`). Execute (Pi-local, one transaction): create one catch-up StudentClass (2026-06-01..08-31,
Charge 11550, Paid 0, Stop 1, `settled_pending`, Memo naming 1249), three unpaid invoices (2026-06/07/08) with one item each, void 1053
(Note += decision_reference); counts asserted; strict `pop.muzha_chen_billing_catchup` audit. Verify: the contract, three invoices and
items as above; 1053 void with the reference.

## Rollback

From the stored snapshot: delete created invoices/items/contract only while untouched (unpaid, no payments/reports); restore 1053 only
while still void with exactly the appended Note. Changed rows are skipped and reported (`ok=false, partial=true`). POP has no production
rollback adapter yet: a rollback is a new Founder-approved POP request built from the snapshot.

## Stop conditions

Any drift code, approval/SHA mismatch, or verify failure: stop, re-run a read-only probe, do not retry blindly. Closeout: POP request
id, sanitized dry-run/execute/verify records, deployed SHA.
