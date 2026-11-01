# Repair Manifest — taught forward-generator lessons over-deducting a course (2026-11-01)

Status: POP operation `forward-overdeduct-reassign-20261101`. Nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. GitHub #4554 (in-app #452). Founder decision 2A.

## Purpose

The nightly forward generator (#1062) added lessons to count courses. Some were taught, so the app now counts more used lessons than were bought
(bought 12, counted 13). `scripts/probes/forward-overdeduct-probe.php` lists the courses (16 on 2026-11-01). Decision 2A: move each taught extra
beyond the bought count onto the same student's later contract of the same subject (higher `StudentClass.ID`). With no later contract the lesson
stays as a gift (no change). Money is untouched.

## Plan (computed at dry-run, ids only, no names)

Parameters: `campus_id` (0 = all campuses), `decision_reference = reassign-forward-overdeduct-20261101`, `expected_digest` (empty on the dry-run), `restore_request_id` (empty).

Selection: `App\Services\ForwardSessionExtras::scan`, rows with `over = true`. Excess = counted used minus bought. Candidates = the course's generated
lessons that are not cancelled/voided and have a positive net ledger count, newest first, the first `excess` of them. Targets = later contracts of the
same student and subject, count mode, not waived, not settled, with room (bought minus counted used, minus moves already planned). Manifest rows
(`class_id, campus_id, session_id, session_date, start_time, to_class_id, class, reason`):

- `move / later_contract`: first target with room, no live lesson at the same date and start, and no approved assessment owned by another teacher.
- `keep / gift_no_later_contract`: the student has no later contract. Never written.
- `review / partial_minutes`: the course has partial-duration lessons (session_id 0). Never written.
- `review / no_movable_lesson`: the course is over but no generated lesson qualifies (session_id 0). Never written.
- `review / insufficient_generated_lessons`: fewer movable generated lessons than the excess, so nothing on that course moves (session_id 0).
- `review / package_linked`: the source belongs to a course package. The reassigner does not move `package_session_ledger`, so this is left to a human (session_id 0). Package-linked later contracts are never targets.
- `review / unbound_usage`: the source has a live sign-in or learning record bound to no lesson. Moving lessons cannot carry it, so the excess might not clear (session_id 0).
- `review / target_duration_differs`: every later contract with room has another lesson length. The moved ledger keeps its minutes and would over-draw it.
- `review / course_settled`, `no_target_with_room` (this includes minute-based targets), `target_slot_taken`, `assessment_locked` (an approved assessment whose teacher is not the teacher of both contracts): listed for a director, never written.

## Execute

One transaction: lock the involved contracts, recompute the plan and compare its digest (`forward_overdeduct_reassign_digest_drift` aborts on any
change), then move each `move` row with `ClassSessionContractReassigner::reassign` (reason `POP forward-overdeduct-reassign #4554`). That service moves
the lesson, sign-in, ledger rows and learning record, recomputes both contracts' counters, and writes a reassignment row and an audit event. Any refusal
aborts the whole run (`forward_overdeduct_reassign_refused`). Then a strict `pop.forward_overdeduct_reassign` event. Snapshot rows are
`{id, from_class_id, to_class_id}`. No `Charge`, `Paid` or `SessionCount` is written.

Verify: each moved lesson is on its target; no lesson is still `move`; no target, and no source whose candidates were all moved, counts more than it bought.

## Rollback

**How to undo a run:** dispatch the same workflow with `restore_request_id` = that run's POP request id:
1. Dry-run with confirm `DRY_RUN_FORWARD_OVERDEDUCT_RESTORE`.
2. The Founder approves with `APPROVE_FORWARD_OVERDEDUCT_RESTORE` and the reviewed digest.

The restore plan reads the reassign request's stored snapshot and lists each lesson still on its target (`restore`) or moved on since (`skip`). It moves
each back with the same service (moving back is the undo). All or nothing: one refused row rolls the whole restore back. `rollback` does the same for
exactly the snapshot ids and reports `skipped_ids`. A rerun after execute is `state=after`.

## Flow

1. Dry-run `pop-forward-overdeduct-reassign.yml` (campus 0 or one campus). Review the `move`, `keep` and `review` rows.
2. The Founder approves that exact digest (`founder-exact-forward-overdeduct-reassign`, super_admin, bound to the deployed SHA).
3. Pi-local execute and verify. Re-run the probe: only `keep` and `review` courses should remain over.
4. Directors decide the `review` rows by hand.
