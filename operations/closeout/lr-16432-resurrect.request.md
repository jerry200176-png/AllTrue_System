# Request: resurrect LearningRecord 16432 (張韙 2026-08-14)

Trigger: `ops-lr-16432-resurrect.yml`

**Scope: LearningRecord id 16432 / ClassSession id 14375 ONLY.** No other row may be written.

## Authorization
Founder asked to finish the 8/14 張韙 eval gap after #1833 deployed. Diagnose
`31946856110` showed CS 14375 still `attended` and LR 16432 still voided
(`VoidedAt=2026-08-16 18:12:40`, VoidReason `由已上調整狀態`). Same-status
PATCH does not call restore; this job applies
`LearningRecordResurrectionPolicy::restoreEligibleForSession` once.

## Mandatory preconditions (any failure = no write)
- production HEAD is a descendant of `ffcfc4fa93ed6f6a6e61775a715434c9ecb62180` (#1833)
- LR 16432 exists, ClassSessionID=14375, TeacherID=232, Status=pending
- CS 14375 exists, Status=attended, StudentClass student id=2034
- either already restored (`VoidedAt` null) **or** VoidReason is exactly `由已上調整狀態` and `VoidedAt` is set

## Authorized mutation
`LearningRecordResurrectionPolicy::restoreEligibleForSession(ClassSession 14375)`
only. No SQL UPDATE, no ledger change, no attendance rewrite.

## Expected result
- LR 16432: VoidedAt/VoidReason/VoidedByUserID null, Status pending
- CS 14375 unchanged (attended)
- Idempotent: if already restored, job succeeds with `no_write_performed`

**No writes if preconditions fail.**

# kickoff 2026-08-16T12:30:00Z — guarded restore of LR 16432 after #1833 deploy
