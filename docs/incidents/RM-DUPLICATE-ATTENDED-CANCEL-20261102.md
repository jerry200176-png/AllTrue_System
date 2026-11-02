# Repair Manifest — double-counted attended lessons and duplicate package records (2026-11-02)

Status: POP operation `duplicate-attended-cancel-20261102`. Nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. GitHub #4554, #4753. Founder decision B (2026-11-02): fix only the clear errors.

## Scope (hard-coded, ids only)

Parameters: `campus_id` (0 = all; filters rows by the student's campus / package campus), `decision_reference = cancel-duplicate-attended-20261102`, `expected_digest`, `restore_request_id`.

Part 1 (case-dump run 41381757085): sessions 41783 / 41799 / 42975 on courses 2879 / 3571 / 3226 each duplicate an attended, deducted lesson on a later contract
(sessions 43450 / 43852 / 43985 on courses 4264 / 4312 / 4329). Row `kind=cancel` only when the lesson exists on that course, the twin exists on the twin course, both courses
belong to one student, date and start match, both are attended/completed/late with ledger net above 0, the old course is over-used (`uncapped_used > session_count`,
`SessionDeductionService::batchExpectedUsedSessionDiagnostics`), the course is not settled and the lesson is not a projected monthly lesson (note `projected-monthly-materialized`; the app's own cancel also edits that schedule row). Else `kind=review` with a reason code, never written.

Part 2: packages 189 and 200 duplicate 190 and 201. Row `kind=close` only when no course points at the duplicate, the twin has a course and matches on student, total / remaining / used sessions and ledger sum, and the duplicate is not stopped.

## Execute

One transaction: lock lessons, courses and packages, recompute the plan and compare its digest (`duplicate_attended_digest_drift`). Per cancel row, as `ClassSessionController` does for attended -> cancelled:
void live sign-ins and learning records (`CourseLeaveCascadeService::voidLiveArtifactsForNonAttendance`), `SessionDeductionService::reverseForSession` (also writes the package ledger of a package course),
Status = `cancelled` through the model (observer audits), `recomputeCounters`. No make-up lesson is appended (the course is over-used). Per close row: `stop = 1`, `closed_reason = duplicate_record`; ledger and twin untouched.
Checked inside the transaction (a miss rolls everything back) and again at verify: cancelled, ledger net 0, no active sign-in, twin still attended with net above 0, course no longer over-used, package retired.

## Rollback

Dispatch the same workflow with `restore_request_id` = the run's POP request id: dry-run `DRY_RUN_DUPLICATE_ATTENDED_RESTORE`, then the Founder approves `APPROVE_DUPLICATE_ATTENDED_RESTORE`.
The snapshot holds, per lesson: Status, Note, voided sign-in / learning-record ids, ledger and package-ledger rows added, the course's package id; per package: stop, closed_reason.
Restore deletes exactly the added ledger rows, un-voids exactly those ids, puts Status/Note back and recomputes course/package counters from the ledger as it is now; all or nothing (a lesson no longer cancelled, or whose reversal rows are gone because that run was already restored, refuses the restore).

## Flow

1. Dry-run `pop-duplicate-attended-cancel.yml` (campus 0, confirm `DRY_RUN_DUPLICATE_ATTENDED_CANCEL`): expect `cancel=3 close=2 review=0`.
2. Approve run with that digest (confirm `APPROVE_DUPLICATE_ATTENDED_CANCEL`); the Founder approves it (`founder-exact-duplicate-attended-cancel`, super_admin, bound to the deployed SHA).
3. Pi-local execute and verify; re-run the dry-run: all rows `review`, state `after`.
