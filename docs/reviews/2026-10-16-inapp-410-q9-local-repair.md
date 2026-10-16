# In-app #410 / issue #4210: local Q9 repair evidence

Status: LOCAL PREPARATION ONLY. Integration owner: Claude. No push, PR, merge,
deployment, production access, credential change or flag activation performed.

## Scope and provenance

- Base: `1911853fe3feca330a219bc6101a6f855f22d17d` (current origin/main).
- Reuses merged #4162, `876a33ce37ce27ae64ac5c860f9856ccb17a422b`.
- Local branch: `fix/4210-q9-undo-guard-local`.
- Read issue #4210 including coordination comment 6226582741, merged #4162,
  current source, open PR list and branch names on 2026-10-16 UTC. No existing
  Q9 implementation or open Q9 PR was found. Overlap scan flags unrelated
  StudentClassController/config/routes work; none of those files is changed.
- Approved rule: refuse undo while renewal slots still differ from the original
  slots recorded by this leave's successor-shift provenance; never shift back.
  Recurrence family: F1 (undo lifecycle does not account for downstream effects).

## Implementation

The shared cascade undo checks successor provenance before every restore/delete.
The by-session desync path checks before voiding a leave sign-in. Both endpoints
return HTTP 422, `undo_leave_successor_shift_active`, a clear reason, and
`affected_lessons` with course/session identifiers plus original/current date and
start time. Matching is exact for the leave ID and scoped to the same student and
subject. Past, completed and cancelled rows are not silently skipped. Restored
original slots no longer count as active; historical note text is retained.

No reversal, migration, new class, auth change, ledger write, UI change or flag
change. The existing default-OFF gate and its response remain intact. Existing
role/campus and downstream consumed-lesson guards remain intact.

## Verification

- RED before implementation: new by-session active-shift regression returned
  200 instead of 422. Two tests, five assertions, one failure; ordinary undo passed.
- GREEN: `source /workspace/cloud-dev/activate.sh` then
  `bash scripts/phpunit-isolated.sh -d memory_limit=512M --filter '(Leave|ScheduleGuard|ClassSession)'`:
  **335 tests, 1659 assertions, PASS**, ephemeral loopback MariaDB with unique
  synthetic test schema, not production.
- New coverage: both undo endpoints, active shift with flag OFF, past/completed
  renewal lessons, desynced leave sign-in, unrelated leave-ID prefix, original
  slots restored. Refusals compare complete rows in ClassSession, StudentClass,
  StudentSingIn, LearningRecord, deduction ledger and schedules before/after.
- Initial broader run hit the environment's 128 MB PHP memory limit; the bounded
  rerun above passed with 512 MB. No tracked runtime config changed.
- `git diff --check`: PASS. `node scripts/arch-fitness-check.mjs`: PASS (15 checks).
- PHPStan on all three touched production files: two `isset.variable` errors in
  unchanged ScheduleController code (current lines 294 and 330; base 291 and 327).
  A separate baseline-file analysis reproduces these same two errors, but also
  loses path-specific baseline suppression, so it is not a clean comparison run.
  No baseline or suppression was changed. PHPStan restricted to the two touched
  services passes with no errors.

## Independent bounded review

A separate reviewer agent read the final four-file code/test diff and found no
blocking findings. Reviewed diff SHA-256:
`7c14658e9f9f82e10f6583245994fcb9f0faff22568f86b7ade55c28f0912e4d`.
Review covered shared-service and desync paths, marker matching, restored slots,
consumed lessons, campus/role preservation and scope. Reviewer did not rerun tests.

## Risks, rollback and remaining integration

- Runtime concurrency interleavings were not tested. Existing inverse lock order
  between by-session undo and shift apply remains in #4210's separate pre-enable
  lock-order work. Guard uses row locks inside existing endpoint transactions;
  it makes no partial writes. Direct service callers must retain a transaction.
- Existing empty-campus permissiveness in undo endpoints is unchanged; this
  patch does not claim an authorization audit or fix it.
- Provenance provides original date/start only. Missing/malformed/lost notes or
  deleted/moved-to-another-student/subject rows are outside this guard's evidence.
- Rollback: discard/revert this local commit; no schema/data rollback required.
- Claude must integrate/recheck overlap and run exact-head CI. #4210's frontend
  preview/confirm/blocker navigation and all remaining apply safety findings
  (sign-in/ledger/marker consistency, lock order, slot awareness, anchor/effective
  teacher validation, token EndDate binding, and other listed review items) remain
  required before activation. This patch alone does not complete #410.
- Production-path verification, in-app writeback and reporter acceptance remain
  unperformed and belong to later authorized integration/release work. Keep
  `LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED` OFF.
