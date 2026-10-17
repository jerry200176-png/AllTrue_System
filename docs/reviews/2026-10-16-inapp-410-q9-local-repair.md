# In-app #410 / issue #4210: local Q9 repair evidence

Status: original local-preparation evidence, followed by Draft publication at
`fb96b145` as recorded below. Integration owner: Claude. The test-isolation
correction below is local only. No merge, deployment, production access,
credential change or flag activation performed.

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
start time. Matching is exact for the leave ID and scoped to the same student; mutable
subject metadata is excluded from provenance lookup. Past, completed and cancelled rows are not silently skipped. Restored
original slots require a reconciled renewal EndDate before they count as inactive;
historical note text is retained.

No reversal, migration, new class, auth change, ledger write, UI change or flag
change. The existing default-OFF gate and its response remain intact. Existing
role/campus and downstream consumed-lesson guards remain intact.

## Verification

- RED before implementation: new by-session active-shift regression returned
  200 instead of 422. Two tests, five assertions, one failure; ordinary undo passed.
- GREEN: `source /workspace/cloud-dev/activate.sh` then
  `bash scripts/phpunit-isolated.sh -d memory_limit=512M --filter '(Leave|ScheduleGuard|ClassSession)'`:
  **336 tests, 1669 assertions, PASS**, ephemeral loopback MariaDB with unique
  synthetic test schema, not production.
- New coverage: both undo endpoints, active shift with flag OFF, past/completed
  renewal lessons, desynced leave sign-in, unrelated leave-ID prefix, original
  slots restored. Refusals compare complete rows in ClassSession, StudentClass,
  StudentSingIn, LearningRecord, deduction ledger and schedules before/after.
- Initial broader run hit the environment's 128 MB PHP memory limit; the bounded
  rerun above passed with 512 MB. No tracked runtime config changed.
- `git diff --check`: PASS. `node scripts/arch-fitness-check.mjs`: PASS (15 checks).
- PHPStan same-path comparison against a detached worktree at the exact unchanged
  base uses the identical command in each backend directory:
  `vendor/bin/phpstan analyse --configuration phpstan.neon --no-progress --memory-limit=512M --error-format=json app/Http/Controllers/ScheduleController.php app/Services/CourseLeaveCascadeService.php app/Services/CourseLeaveCascadeSuccessorShiftService.php`.
  Both exit 1 with exactly two file diagnostics and no general errors:

  | Identifier | Exact message | Base line | Final line |
  | --- | --- | --- | --- |
  | `isset.variable` | Variable $courseMeta in isset() always exists and is not nullable. | 291 | 294 |
  | `isset.variable` | Variable $courseMeta in isset() always exists and is not nullable. | 327 | 330 |

  The corresponding source lines are byte-identical (`&& isset($courseMeta)` and
  `if ($courseId > 0 && isset($courseMeta)`); three added import lines explain
  the offsets. Normalized identifier/message/line comparisons match exactly:
  **zero new diagnostics**. The detached base retains normal relative file paths
  and path-specific baseline rules. The two touched services pass separately.
  No baseline/suppression change. Evidence JSON:
  `/workspace/scratch/q9-phpstan-base.json` and `q9-phpstan-head.json`.
  Config SHA-256, identical in both worktrees:
  `phpstan.neon`: `c23eed3f68ec9ab8c641ab32c3e1b2db4e5a7d882da1c3d840cc49efea230152`;
  `phpstan-baseline.neon`: `906cffbcfb6b5adada6862dc1fd504481c725ea968a3d2d8316f539add549d73`.

## Bounded transaction and stale-state verification

Source inspection finds these transaction boundaries and lock sequences:

| Path | Transaction and lock sequence |
| --- | --- |
| Shift apply | `apply()` opens transaction, re-plans; old course lock, old session locks, successor course/session locks; all move/tail/end-date writes commit together. |
| Schedule-ID undo | Controller transaction; shared service locks old course, old sessions, then Q9 affected rows; refusal throws before restore/delete and rolls back. |
| Session-ID undo | Controller transaction; selected session lock, old course lock; shared service locks old sessions and affected rows. Desync branch invokes Q9 before voiding sign-ins. |

The shared old-course lock serializes ordinary apply/undo writes. By-session undo
can first hold the selected session while apply holds the old course: the inverse
order can deadlock; InnoDB must abort a transaction, not commit partial work.
No retry policy or lock-order change is included; #4210 already tracks that work.

The smallest regression for the stale-state safety concern uses two independent
MariaDB connections in `test_undo_with_a_stale_snapshot_sees_the_committed_successor_shift`:

1. Commit synthetic fixtures so both connections can observe them. Establish a
   REPEATABLE READ snapshot on the undo connection with empty renewal note.
2. Preview/apply on the primary connection and commit its real service transaction.
3. Prove a normal read on the undo connection still sees the empty note.
4. Invoke the real by-session undo endpoint on that stale connection. Its locking
   reads see the committed provenance, return 422 with affected lesson ID, and
   leave all six authoritative table snapshots unchanged.

The final local test restores/purges connections and deletes only its own
synthetic rows, preserving the schema and RefreshDatabase state. The originally
published version forced remigration; the correction and reason are below. It needs no fork, sleeps or timing
assumption. GREEN: **1 test, 10 assertions**. Mutation: remove only Q9 query's
`lockForUpdate()`; test fails with **expected 422, received 200** (1 test, 7
assertions, 1 failure). Restore the locking read: related suite passes with the
final counts above. Production code is unchanged from `a3e4a469`.

This proves the relevant committed-shift/stale-undo interleaving, not every
possible concurrent interleaving or deadlock recovery. Default-OFF integration is
safe within this bounded evidence; lock-order/liveness remains a pre-enable
#4210 dependency and exact-head CI remains required.

## Independent bounded review

A separate reviewer agent read the final four-file code/test diff and found no
blocking findings. Reviewed diff SHA-256:
`7c14658e9f9f82e10f6583245994fcb9f0faff22568f86b7ade55c28f0912e4d`.
Review covered shared-service and desync paths, marker matching, restored slots,
consumed lessons, campus/role preservation and scope. Reviewer did not rerun tests.
A second bounded independent review found no issues with the stale-snapshot test,
fixture cleanup, or validity of the PHPStan comparison. Its reviewed test-diff
SHA-256 is `494d7da545f0b6f0a4e8d11c93fe3b881bc919a7542c1e9e216ce447c11fb307`.

## Risks, rollback and remaining integration

- The committed-shift/stale-undo interleaving is verified above. Simultaneous
  inverse lock acquisition and engine deadlock recovery are not simulated;
  existing lock-order/liveness work remains in #4210 before activation. Direct
  service callers must retain a transaction. No new blocker prevents integration
  of this guard with the activation flag OFF, subject to exact-head required CI.
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


## Draft publication and test-isolation correction

Draft PR #4212 publishes the unchanged reviewed `fb96b145`:
https://github.com/jerry200176-png/AllTrue_System/pull/4212.
Remote SHA was verified as `fb96b1454ddcf934634fb2449bcf1c334cec1271` against
base `1911853f`. Overlap recheck found no file overlap. No merge or activation.

Exact-head dispatched workflows:
- Presubmit 38982816140: PASS.
- Docs Integrity 38982821325: PASS.
- Secret Scan 38982825260: PASS.
- Security Scan/full PHPStan 38982829173: PASS.
- CI 38982812599: control-plane, golden scenarios and frontend PASS; PHPUnit
  FAIL (4014 tests, 19575 assertions, 3 errors, 11 skipped, 1 incomplete).

The three errors are a test-isolation regression introduced by the new test:
forcing `RefreshDatabaseState::$migrated=false` remigrates after earlier index
tests left `APPLY_CLASS_SESSION_UNIQUE_INDEX=1` but removed the index. The next
migration recreates it and breaks later deliberately duplicate slot fixtures in
MonthlyBillingSlipTest, OrphanScheduledSessionsTest and PaymentReportApiTest.
No Q9 endpoint test failed. This is not classified as pre-existing CI failure.

Local correction: remove the forced remigration; in finally, delete only this
test's courses/sessions, session audit/sign-in rows, user/token/membership and
student, then balance the existing teardown transaction. No production-code or
schema change. Existing schema and process migration state are preserved.

Deterministic reproduction command:
`bash scripts/phpunit-isolated.sh -d memory_limit=512M --filter '(ClassSessionD1UniqueIndexTest|LeaveSuccessorShiftTest|MonthlyBillingSlipTest|OrphanScheduledSessionsTest|PaymentReportApiTest)'`.
- Published test: 111 tests, 596 assertions, 3 errors, 7 skipped.
- Corrected test: 111 tests, 647 assertions, 0 errors, 7 skipped.
The larger scheduling/regression suite is recorded in the handoff result.
Independent bounded review found no issues in the correction; reviewed test-diff
SHA-256: `99d46d5973c7c83128b3b75951a988532a4480200cb5f2b42f6d7775621ce776`.

Publication scope was the unchanged reviewed `fb96b145`, so this new correction
has not been pushed. Integration remains blocked on publishing/integrating the
reviewed test-only correction and rerunning exact-head CI. The server guard and
activation flag are unchanged. The public Draft body records the failed run and
root cause rather than claiming readiness.


## Ready-review P1 corrections (merge preparation)

Automated review at `9eef9621` identified two valid guard gaps:
- `discussion_r4300423240`: supported subject edits on either contract hide rows
  from a same-subject lookup. StudentClassController::update unsets StudentID
  (line 1729), so same-student scoping is retained while SubjectID is removed.
  An explicit locking join makes course ownership and provenance current together,
  preserving the campus boundary instead of querying global note markers.
- `discussion_r4300423235`: restoring original lesson slots leaves the shifted
  renewal EndDate behind. For restored marked slots, lock the owning course and
  its current sessions; require a nonnull EndDate equal to the latest noncancelled
  lesson date. Missing/inconsistent boundaries fail closed with
  `renewal_end_date_mismatch`, affected course/session identifiers and observed
  boundary dates. Nothing is repaired or shifted automatically.

RED: four new subject/boundary cases produced 3 failures (expected 422, got 200);
reconciled boundary already passed. GREEN includes both subject edits, actual
shift followed by restored slots with stale/reconciled EndDate, plus a foreign
campus student's copied marker that must neither block ordinary undo nor move
that student's lesson. Existing stale-snapshot and cleanup regressions remain.

The boundary rule is conservative: an unrelated later EndDate change may require
explicit reconciliation before undo. Original pre-shift EndDate is not recorded
by #4162, so the guard does not guess or overwrite it. Existing lock-order
liveness and deleted/lost provenance limitations remain pre-enable dependencies.
Default-OFF flag, role/campus checks and past/consumed records are unchanged.
Exact-head CI and final independent review are recorded on PR #4212.
