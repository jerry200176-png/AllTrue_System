# In-app #410 / issue #4210: bounded Q9 repair evidence

PR #4212 records the current published head, exact-head checks and independent review.
Codex owns engineering/integration after Jerry's ownership update; separate Codex review is required.
Queue/merge remain held for root review of changes since the original `9eef9621` GO.
No deployment, production data access, credential change or activation is authorized.

## Scope and provenance

- Base: `1911853fe3feca330a219bc6101a6f855f22d17d`.
- Reuses merged backend #4162, `876a33ce37ce27ae64ac5c860f9856ccb17a422b`.
- Branch: `fix/4210-q9-undo-guard-local`; only its five application/test/evidence files change.
- Initial inspection covered issue #4210/coordination comment6226582741, source, merged #4162 and open PRs/branches. No existing Q9 implementation or competing Q9 PR was found.
- Overlap involved unrelated StudentClassController/config/routes work; those files remain untouched. #4213 belongs to another owner.
- Rule: refuse canceling the old leave while its renewal shift remains active; clear reason and affected renewal identifiers; never reverse lessons automatically.
- Recurrence family F1: undo lifecycle omitted downstream effects. R2/T2, no schema/UI/auth/flag change.

## Current implementation

Shared cascade undo checks Q9 before restore/delete; by-session desync checks before sign-in writes.
Both endpoints return422, `undo_leave_successor_shift_active`, explanation and affected course/session IDs with original/current date/start/end.
Exact leave-ID provenance uses a current locking ownership join scoped to immutable StudentID, excluding mutable SubjectID.
Past/consumed/cancelled renewal rows are included, never rewritten or silently ignored.
Original complete slots and renewal EndDate must be reconciled explicitly; EndDate must match the latest noncancelled lesson under locks.
Apply stores successor/count anchors on old leave and owned tail outside rebuildable renewal rows; missing/partial renewal metadata fails closed.
New renewal provenance includes original EndTime. Incomplete legacy markers refuse rather than guessing.
After resolving the actual leave session, shared undo resolves its shift-owned tail even if the schedule endpoint's stale start_time yielded null ID.
Successor-owned cleanup removes only an unused scheduled tail whose complete start instant is future; past/consumed/artifact/ledger-backed tails refuse.
Successful cleanup voids only the selected leave sign-in, recomputes old EndDate and marks its anchor resolved; later ordinary leave remains undoable.
Historical legacy-SHIFT notes cannot select reverse-shift for successor-owned cleanup; ordinary legacy undo retains its existing branch.
Apply checks Unicode character capacity before saving lifecycle notes, including room for the old leave's eventual undo note.
Over-capacity metadata returns clear422 with affected IDs; no truncation or partial writes. Existing note text remains intact when it fits.
Default-OFF `LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED`, role/campus checks and consumed-lesson guards remain intact.

## Failing-before / passing-after regressions

- Initial active-shift undo: expected422/got200; ordinary unrelated undo already passed.
- Both endpoints, flag-OFF undo protection, consumed/past renewal rows, desync sign-ins, exact leave-ID prefixes and foreign-campus copied markers are covered.
- Subject edits / stale EndDate: four cases produced3failures against9eef9621; reconciled boundary already passed.
- Against3e8db9d6, four new cases failed: rebuild/end-time undo returned200, safe desync omitted tail/boundary cleanup, consumed desync returned200. `/tmp/q9-latest-red.txt`.
- Againstbbf21b06, elapsed/later-today cases:2tests11assertions1failure(expected422/got200 for elapsed); full start-instant guard fixes it.
- Against15c877a7, historical-policy state through real apply/reconcile/undo:1test3assertions1failure(expected200/got422); successor cleanup now bypasses legacy reversal.
- Against56d4c3a2, exact boundary plus schedule null-ID cases:9tests39assertions6failures. Elapsed/ledger tails returned200, safe cleanup left a live sign-in, historical note returned422, old lifecycle overflow applied unsafely, renewal overflow returned500. `/tmp/q9-new-p1-p2-boundary-red.txt`. Earlier240-character cases both returned500.
- Current schedule regressions use stale schedule start_time with actual apply and renewal reconciliation; safe, elapsed, ledger and historical cases cover both refusal and successful cleanup.
- Capacity tests cover exact255-character complete lifecycle notes applying/undoing intact, and one-character overflow refusing unchanged six-table snapshots (Unicode text, old leave and renewal).
- Final local counts and exact-head CI results are recorded in the handoff and PR conversation; canceled/older heads are not counted as current passes.
- Synthetic loopback MariaDB only: `source /workspace/cloud-dev/activate.sh`, then `bash scripts/phpunit-isolated.sh -d memory_limit=512M --filter 'Leave|ScheduleGuard|ClassSession|MonthlyBillingSlipTest|OrphanScheduledSessionsTest|PaymentReportApiTest'`.
- Refusals compare complete ClassSession, StudentClass, StudentSingIn, LearningRecord, deduction ledger and schedules snapshots.

## Transaction / stale-state evidence

Apply opens a transaction and re-plans under old course/session, successor course/session locks; move/tail/boundary writes commit together.
Both undo controllers retain their transactions, permission checks and shared cascade authority; refusals roll back before observable changes.
The old-course lock serializes ordinary apply/undo writes. By-session undo's session->course order can invert apply's course->session order and deadlock.
This bounded patch does not change retry policy or claim every deadlock/recovery interleaving is tested.
The deterministic stale-snapshot regression commits synthetic fixtures, establishes an empty REPEATABLE READ snapshot on another connection, then applies the real shift on the primary connection.
It proves the ordinary read remains stale while actual undo's locking read sees the committed shift, refuses422 and leaves all six tables unchanged.
Mutation removing only Q9 `lockForUpdate()` fails(expected422/got200); restoring it passes(10assertions).
Cleanup deletes only its own committed synthetic rows and restores/purges connections; no sleeps, forks or schema remigration.

## Historical CI isolation correction and static analysis

Atfb96b145, forced remigration in the new stale-snapshot test reapplied an index flag leaked by earlier tests, producing3duplicate-fixture errors in MonthlyBillingSlipTest, OrphanScheduledSessionsTest and PaymentReportApiTest.
Fix9eef9621 deletes only its own synthetic rows. The111-test reproduction failed before(3errors) and passed after(647assertions,7skipped).
Full CI at9eef9621 passed:4014tests19626assertions11skipped1incomplete,83.13%coverage; frontend1418passed1skipped and build passed.
Historical run: https://github.com/jerry200176-png/AllTrue_System/actions/runs/38983876121 .
Touched services pass PHPStan without suppressions or baseline changes; final remote full-PHPStan is authoritative.
Earlier same-path base/head analysis found only the identical pre-existing controller `isset.variable` diagnostics(base291/327 -> head294/330 due imports), zero new diagnostics.
Evidence: `/workspace/scratch/q9-phpstan-base.json`, `q9-phpstan-head.json`; configs were byte-identical.
`git diff --check` and architecture fitness(15checks) passed at the initial bounded review; new classes/migrations are not introduced.

## Independent review and publication

Earlier separate reviewer evidence at9eef9621 and3e8db9d6 remains historical, not approval of later changes.
Separate Codex reviewer reviewed full headsbbf21b06,15c877a7 and56d4c3a2, found the elapsed-today and historical-policy gaps and verified their corrections.
GitHub automated review of56d4c3a2 found schedule null-ID fallback(P1,r4300564231) and note capacity(P2,r4300564232); both are addressed with new RED/GREEN cases.
Every finding requires a concrete reply and resolution only after verified correction. Current final-head reviewer evidence is on the PR.
Reviewer source/diff checks do not imply an independent test rerun or a second GitHub approval identity.
Publication was authorized separately; materially changed behavior beyond9eef9621 remains held for root final diff/risk review.
Presubmit flagged intentional #4162 replacements: add stable anchor to existing leave write and original EndTime to renewal note. Reasons are declared in PR body using the repository's documented `intentional-revert` label; no gate code/rules changed.

## Risks, rollback and remaining #410 work

Complete manual removal of every old/renewal marker cannot be inferred. Legacy incomplete end/count metadata requires explicit reconciliation.
Notes remain lifecycle metadata in existing bounded columns; capacity is now checked, no migration/backfill/truncation.
Renewal EndDate reconciliation is conservative after unrelated edits; no boundary is guessed or changed on refusal.
Existing lock-order/liveness remains pre-enable work; default-OFF preparation is not production activation.
Remaining #4210 items include frontend preview/confirm/blocker navigation, apply sign-in/ledger/leave-marker consistency, slot-aware tail/anchor/effective-teacher validation, EndDate token binding and other listed findings.
This Q9 repair does not complete410. Activation/deployment/production data access require their own authorization.
Rollback: revert this PR; no schema or data migration rollback. No other owner's branch or deployed artifact is changed.
