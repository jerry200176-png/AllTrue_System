# Repair Manifest — forward-generator extras on fully used courses (2026-11-01)

Status: POP operation `forward-extra-cancel-20261101`. Nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. GitHub #4758 (in-app #452).

## Purpose

The nightly forward generator (#1062) added a `scheduled` lesson to courses whose bought lessons were already all used. Marking one would
deduct one more than was bought. `scripts/probes/forward-overdeduct-probe.php` (`pending_extras`) lists them: 16 courses, 21 lessons,
campuses 3, 4, 9, 11, 15, 17, 26 on 2026-11-01.

## Plan (computed at dry-run, ids only, no names)

Parameters: `campus_id` (0 = all campuses), `decision_reference = cancel-forward-extra-sessions-20261101`, `expected_digest` (empty on the dry-run).

Selection: `App\Services\ForwardSessionExtras::scan`, the scan the probe also uses. A course is in when its ledger-used count is at least what it
bought (and not over yet) and it still holds a generator lesson with status `scheduled`. Each such lesson is a manifest row
(`class_id, campus_id, session_id, session_date, start_time, class, reason`):

- `cancel / future`: no live sign-in, no live learning record, no ledger row, course not settled, and the date is today or later (Asia/Taipei).
- `cancel / past_duplicate_later_course`: same, past, but a later course of the same student and subject has a non-cancelled lesson at the same date and start.
- `review / activity`: a live sign-in, a live learning record or a ledger row points at it.
- `review / course_settled`: the course is settled (the model refuses edits there).
- `review / past_no_duplicate`: past and unmarked with no duplicate. It may have been taught, so a director decides. Never written.

## Execute

One transaction: reset the settlement-lock cache, lock the reviewed lessons and their courses, recompute the plan and compare its digest
(`forward_extra_cancel_digest_drift` aborts on any change, including a review row turning cancellable). Snapshot the cancel rows (`id, Status, Note`)
and their courses' counters, set `Status = cancelled` through the ClassSession model (creating/updating guards; `ClassSessionObserver` audits each row),
then write a strict `pop.forward_extra_cancel` event. The generator creates no `Schedule` row, and no counter, ledger, `Charge`, `Paid` or `SessionCount`
is written.

Verify: the ids are cancelled, the course counters equal the snapshot, and no row is still cancellable.

## Rollback

**How to undo a cancel run:** dispatch the same workflow with `restore_request_id` = that run's POP request id:
1. Dry-run with confirm `DRY_RUN_FORWARD_EXTRA_RESTORE`.
2. The Founder approves with `APPROVE_FORWARD_EXTRA_RESTORE` and the reviewed digest.

The restore plan reads the cancel request's stored snapshot and lists each lesson still `cancelled` (`restore`) or moved on since (`skip`). The Pi runs it like any approved execute (#4736 pattern). It is all or nothing: one skipped row rolls the whole restore back.


`rollback` sets `Status` back to `scheduled` for exactly the snapshot ids. A row already moved on, or whose student slot is taken since, is skipped
and reported (`skipped_ids`). A rerun after execute is `state=after`.

## Flow

1. Dry-run `pop-forward-extra-cancel.yml` (campus 0 or one campus). Review the `cancel` and `review` rows.
2. The Founder approves that exact digest (`founder-exact-forward-extra-cancel`, super_admin, bound to the deployed SHA).
3. Pi-local execute and verify. Re-run the probe: `pending_extras` should hold only the `review` lessons.
4. Directors decide the `review` rows by hand.
