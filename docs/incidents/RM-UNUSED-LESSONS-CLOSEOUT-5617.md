# Repair Manifest — close contract 2201 at its used lessons (#5617)

Status: POP operation `unused-lessons-contract-closeout-5617`; nothing runs until a Founder-approved POP request. Risk: R3/T3 (production data repair, billing).
Founder 2026-11-25 (in-app #594, GitHub #5617 comment 7154361077, option A): StudentClass 2201 keeps the 2 lessons it used; nothing else changes.

Scope: `UnusedLessonsContractCloseoutStrategy::MANIFESTS['closeout-unused-lessons-5617-20261125']`, one transaction:
- ClassSession 50637, 50638, 50639, 50640, 50641, 50642 (2026-10-13 to 2026-11-17, weekly) `scheduled` to `cancelled`;
- StudentClass 2201: SessionCount 8 to 2, RemainingSessions 6 to 0, Charge to Rate x 2 (the `ContractAmendmentService::chargeForCount` rule), closed_reason `contract_amended`; Stop, EndDate, TotalHours, UsedSessions unchanged;
- Invoice 2821: TotalAmount to the new Charge; Status from the reconciliation projection (`paid`); course Paid/PayDate from `BillingController::syncStudentClassPaidFromInvoice`.
Not written: taught lessons, Payment, payment_reports, receipts, ledger, InvoiceItem. Amounts are derived from the row (Rate x count), never stored in code or here.
Audit: `pop.unused_lessons_contract_closeout`, one strict row (course, campus, counts, reference, request id; no amounts).

Guards (plan, and again under the course, lesson and invoice row locks; any miss fails the whole plan, nothing is written): course exists, campus 17, count mode, no package, Stop 1, not settlement-locked, ledger-used lessons and UsedSessions both 2, Pay 0, no active pricing amendment, no per-session charge, no invoice correction or entitlement / credit transfer, exactly one live bill (by anchor or item link) and it is 2821 with no items, no billing period, no zero-amount mark, PaidAmount and net payments both Rate x 2, no pending payment report; each manifest lesson exists on 2201 at its date, is in the past and has no sign-in, learning record or ledger row; no other `scheduled` lesson on 2201. State `before` also needs SessionCount 8, RemainingSessions 6, Charge and bill total Rate x 8, closed_reason empty, Paid 0, bill `partial`. State `after` is the target above with bill `paid` and Paid 1. Anything else is `drift` (error `state_drift` plus the before-misses).

Run: `.github/workflows/pop-unused-lessons-closeout-5617.yml`, `attempt=1`, `deployed_backend_sha` = production `backend_sha`.
1. `mode=dry-run`, confirm `DRY_RUN_UNUSED_LESSONS_CLOSEOUT_5617` (no click; read-only). Expect `ok=True state=before errors=-` and counts `lessons=6 from_count=8 to_count=2 ledger_used=2 other_scheduled=0 live_invoices=1 to_cancel=6`. Any error code: stop, re-read the data, do not loosen a guard.
2. `mode=approve`, same `attempt`, confirm `APPROVE_UNUSED_LESSONS_CLOSEOUT_5617`, Founder click on `production-activation`. The Pi scheduler (`pop:execute-approved`) runs execute then verify; the job ends `pi_execute=succeeded pi_verify=succeeded audit=verified rollback_snapshot=present`. If the approval expires unconsumed, rerun both modes with the next `attempt`.

Read back (triage db, ids only): lessons 50637-50642 `cancelled`; 17806/17815 unchanged; StudentClass 2201 SessionCount 2, RemainingSessions 0, Charge = Rate x 2, closed_reason `contract_amended`, Paid 1; Invoice 2821 TotalAmount = Charge = PaidAmount, Status `paid`; Payment and payment_reports rows unchanged.

Rollback: the execute record keeps the before values (lesson statuses; course SessionCount, RemainingSessions, Charge, closed_reason, Paid, PayDate; bill TotalAmount, Status, updated_at). `UnusedLessonsContractCloseoutStrategy::rollback` restores them only from state `after` with no new money or scope drift; a lesson that gained attendance since stays cancelled and is reported in `skipped_ids`. This adapter has no rollback mode: undoing is a new Founder-approved POP rollback of the same request. A re-run of execute on a contract already in `after` returns `already_applied` with an empty snapshot (no rollback authority is invented). The 撤銷調整 button does not apply: no `settlement_snapshot` is written.
