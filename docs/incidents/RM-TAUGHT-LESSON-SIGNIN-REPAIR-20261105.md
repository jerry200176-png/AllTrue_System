# Repair Manifest — taught, debited lessons with no sign-in (2026-11-05)

Status: POP operation `taught-lesson-signin-repair-20261105`. Founder approved the repair 2026-11-05; nothing runs until the Founder approves a specific dry-run digest.
Risk: R3/T3. GitHub #4925 #4927 #4931 (in-app #502, #504). Code fixes: #4924 (enrollment and add-session paths, 待補點名), #4931 (calendar status change, separate PR).

## Purpose

Several paths marked a past lesson taught and debited it but wrote no sign-in: course enrollment with 已上過 or 系統判定補登, add-session
with auto-approve on an ended lesson, and the calendar's single-lesson change from scheduled to attended. Part-time payroll and the
attendance views read sign-ins, so the lesson was taught and debited but unpaid, and the payroll warning said 沒點名.

## Plan (computed at dry-run, ids only, no names)

Parameters: `campus_id` (0 = all), `decision_reference = repair-taught-lesson-signin-20261105`, `expected_digest` (empty on the dry-run).
The rule is DB facts, not the path that made the lesson:

- lesson dated 2026-08-01 (part-time payroll reads sign-ins from then on) up to yesterday (Asia/Taipei);
- status `completed` or `attended`;
- ledger net exactly 1 for its course and lesson (`session_deduction_ledger`), so an undebited pending evaluation stays with the normal approve / 補登 flow;
- no `StudentSingIn` row at all for the lesson (live or voided).

Each row: `session_id, class_id, campus_id, session_date, start_time, payee_teacher_id, employment_type, month, month_locked, source, class, reason`.
The payee resolves as part-time payroll does (occurrence, substitute, else contract teacher). `source` only labels the path
(`enrollment_confirmed` = record 「（系統自動核准）」, `add_session_auto_approve` = 「（系統補登加課）」, `enrollment_backfill` = note 系統判定補登, `other`).
`totals` gives `write`, `review`, `part_time`, `month_locked`, `by_source` and `by_campus` (`write`, and `other` = lessons not from the enrollment paths).

- `write / taught_debited_no_sign_in`: the payee is an existing user.
- `review / payee_unresolved`: listed, never written.

## Execute

One transaction: lock the listed lessons, recompute the plan and compare its digest (`taught_signin_digest_drift` aborts on any change), then insert one
`present` sign-in per `write` row with the query builder: payee as teacher, scheduled start and end, `SessionDeducted = 1` (the ledger already holds
the debit), `Memo = pop_taught_signin_20261105`. Before commit it asserts exactly one live sign-in per lesson and that the ledger rows and net of
those lessons did not move (`taught_signin_not_exactly_one`, `taught_signin_ledger_moved`), then writes a strict `pop.taught_lesson_signin_repair`
audit event. No ledger, course counter, lesson status, learning record or payroll row is written.

Locked payroll months: the locked run keeps its snapshot (`payroll_month_status`, `payroll_runs`, `payroll_run_lines` are never written). Rows with
`month_locked = true` go to next month's payroll adjustment (Founder 2026-11-05); unlocked months pick the lessons up on their own.

Verify: each lesson has exactly the created live sign-in, its status is unchanged, its ledger net is still 1, and no `write` row remains.

## Rollback

`rollback` deletes exactly the snapshot sign-ins (matched by id, lesson and memo); one already gone is reported in `skipped_ids`. The lesson status
was never written, so there is nothing to restore. A rerun after execute is `state=after`.

## Flow

1. After #4924 is live, dry-run `pop-taught-lesson-signin-repair.yml` (`mode=dry-run`, `campus_id` or 0, confirm `DRY_RUN_TAUGHT_SIGNIN_REPAIR`, deployed backend SHA).
   Read the job summary / artifact: per-campus `write` and `other`, `part_time`, `month_locked`. Check session 47205 (campus 9) is a `write` row.
2. The Founder approves that exact digest (`mode=approve`, confirm `APPROVE_TAUGHT_SIGNIN_REPAIR`, `expected_digest`; policy `founder-exact-taught-lesson-signin-repair`, super_admin, bound to the deployed SHA).
3. Pi-local execute and verify. Hand the `month_locked` part-time rows to next month's payroll adjustment; review any `review` rows by hand.
