# Repair Manifest — duplicate history pins over taught past slots (2026-11-04)

Status: POP operation `duplicate-history-pin-repair-20261104`. Nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. GitHub #4901 (in-app #499). Code fix: the pin pass no longer adds a second pin (separate PR).

## Purpose

On a contract teacher change the legacy pin pass (`pinPastSessionsToFormerTeacherAfterContractTeacherChange`) added a second history pin
(a `rescheduled` anchor plus a `scheduled` child with `original_schedule_id`) at the OLD contract teacher over past slots that already had a live
pin and a sign-in at the real teacher. The newest pin wins in the effective-teacher resolver, so the taught lesson showed the wrong teacher
although the learning record and sign-in were untouched. Known case: course 3399, schedules 15638, 15639 (2026-10-18) and 15640, 15641 (2026-10-25).

## Plan (computed at dry-run, ids only, no names)

Parameters: `campus_id` (0 = all), `decision_reference = repair-duplicate-history-pins-20261104`, `expected_digest` (empty on the dry-run).
The shape is detected, not hard-coded. Scope: live pins (`status=scheduled`, `original_schedule_id` set, not `extra`, anchor `rescheduled`) on slots before today (Asia/Taipei)
whose slot has a non-voided attendance sign-in with a teacher E. Rows (`class_id, campus_id, pin_id, anchor_id, pin_teacher_id, evidence_teacher_id, session_date, start_time, class, reason`):

- `delete / duplicate_over_older_pin`: an older live pin on the slot already names E, this one does not, and its anchor has no other live child.
- `review / shared_anchor`: the anchor still has another live child; listed, never written.
- `review / pin_disagrees_with_sign_in`: any other disagreement, including a lone pin. Listed, never written: RFID and leave sign-ins stamp the contract teacher, so a real substitute or reschedule pin looks the same.

## Execute

One transaction: lock the listed schedule rows, recompute the plan and compare its digest (`duplicate_pin_digest_drift` aborts on any change), snapshot the full
rows of every pin and anchor to delete, delete exactly those rows with the query builder, then write a strict `pop.duplicate_history_pin_repair` audit event (`reason_code`, `row_count`, `outcome`).
No ClassSession, LearningRecord, sign-in, ledger or billing row is written.

Verify: the snapshot ids are gone and no `delete` row remains.

## Rollback

`rollback` re-inserts exactly the snapshot rows by id; an id taken since is skipped and reported (`skipped_ids`). A rerun after execute is `state=after`.

## Flow

1. Dry-run `pop-duplicate-history-pin-repair.yml` (`mode=dry-run`, `campus_id=17` or 0, confirm `DRY_RUN_DUP_PIN_REPAIR`, deployed backend SHA). Check that pins 15639 and 15641 (anchors 15638, 15640) are `delete` rows for course 3399.
2. The Founder approves that exact digest (`mode=approve`, confirm `APPROVE_DUP_PIN_REPAIR`, `expected_digest`; policy `founder-exact-duplicate-history-pin-repair`, super_admin, bound to the deployed SHA).
3. Pi-local execute and verify. Review the `review` rows by hand.
