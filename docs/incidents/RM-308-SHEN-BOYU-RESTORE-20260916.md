# Repair Manifest — in-app #308 沈柏宇 contract reopen

Status: Founder-authorized via reporter request (comment #758) + this manifest;
pending protected production execution

Decision reference: `founder-go-inapp-308-shen-restore-20260916`
Scope: student class `#3495` (campus 9／沈柏宇／數學) only. Other students,
courses, invoices, payments, and sessions are out of scope.

## Business truth

- Director amended SC `#3495` from 8 → 4 sessions on 2026-09-10 (audit `#1020`).
- Two sessions already attended (`#33542` 2026-09-02, `#33543` 2026-09-09).
- Intended post-amendment state under the fixed rule: SessionCount `4`,
  Used `2`, Remaining `2`, Stop `0`, no `closed_reason`.
- Buggy path zeroed remaining and closed the contract; cancelled six future
  sessions `#33544`–`#33549`. Production now shows RemainingSessions `2` but
  still `Stop=1` / `closed_reason=contract_amended`, so the UI blocks all
  edits. Reporter asked to restore operability.

## Exact preconditions

Abort with no write unless all remain true immediately before execution:

- `Student` id `396`, name exact `沈柏宇`, CampusID `9`.
- `StudentClass #3495`: StudentID `396`, TeacherID `289`, SubjectID `66`,
  ScheduleMode `count`, SessionCount `4`, UsedSessions `2`,
  RemainingSessions `2`, Stop `1`, closed_reason `contract_amended`.
- Attended sessions `#33542` and `#33543` remain attended on this SC.
- Cancelled sessions `#33544`–`#33549` remain `cancelled` with note containing
  `合約提前結束取消`.
- No financial mutation: do not change Charge, Invoice, Payment, or
  PaymentReport.

Idempotent target (already repaired): Stop `0`, closed_reason null,
EndDate null, sessions `#33544` and `#33545` are `scheduled`. Exit success
without rewrite.

## Canonical mutation sequence

1. Reopen `#3495`: `Stop=0`, `closed_reason=null`, `EndDate=null`; keep
   SessionCount/Used/Remaining at `4/2/2`.
2. Append a repair record onto `settlement_snapshot` (do not erase the
   original amendment snapshot).
3. Restore only the two entitlement sessions `#33544` and `#33545` to
   `scheduled` and strip the amendment-cancel note. Leave `#33546`–`#33549`
   cancelled (excess over Remaining=2).
4. Re-read and assert Stop/closed_reason/EndDate and the two restored
   session statuses.

## Evidence

Workflow `.github/workflows/ops-inapp-308-shen-restore.yml` is the Data
Repair Gate. Attach run URL and sanitized pre/post JSON to closeout.
No local shell, ad-hoc SSH session, migration, or unbounded SQL is
authorized.
