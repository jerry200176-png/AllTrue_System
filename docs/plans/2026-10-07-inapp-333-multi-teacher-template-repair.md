# In-app 333 (#3138): multi-teacher template contamination: repair plan (dry-run)

Status: **PLAN ONLY**. No production write is authorized by this document. The
repair (step 3) is R3/T3 and needs Founder GO on the exact manifest.

## What went wrong

Before #3500 (merge `f3aa9efc`, 2026-10-04), `EnrollmentService` created one
`StudentClass` per teacher (`subject::teacher` group) but filtered
`day_time_slots` by subject only. Each per-teacher contract therefore stored
**every sibling teacher's weekday/time** in `week1-6/time1-6` (and `week`/`time`).

The enrollment's own `ClassSession` rows were per-teacher correct. The damage is
in the weekly template, which later paths read:

- the recurring overlap guard counts template slots, which causes false conflicts;
- rebuild, remap and tail-append generate sessions on template slots, so lessons
  appear on another teacher's weekday/time. When both siblings hold that slot,
  the student has two overlapping lessons. That is the report.

#3500 stops new contamination. Existing contracts keep the wrong template until
they are repaired.

## Step 1: read-only evidence (this PR)

Production-case-dump case `inapp_333_multi_teacher_templates`. It records IDs,
weekday/time slots, statuses and flags only, with no names. It takes no inputs.

- Siblings: `StudentClass` rows with the same `StudentID` and `SubjectID`, a
  different `TeacherID`, and overlapping `StartDate..EndDate`. Each teacher's
  contract starts on its own first lesson, so `StartDate` differs between
  siblings and is not used as a key.
- Own slots: the `(ISO weekday, HH:MM)` of the contract's live sessions in its
  `[StartDate, StartDate+13d]`. Reschedule exceptions are excluded:
  `IsContractException=1`, or a `schedule_change_log` `to_date/to_time` match.
- A contract with no own evidence whose template shares slots with a sibling is
  emitted as `review_only: no_own_evidence`.
- Foreign slot: a template slot that is a sibling's own slot and not this
  contract's.
- Every live session of a contaminated contract on a foreign slot is
  classified:

| Bucket | Rule | Proposed handling |
|---|---|---|
| `A_future_duplicate` | future, `scheduled`, no deducted sign-in, no live learning record, `session_deduction_ledger` net = 0, sibling has a live session at the same date+time | cancel this row (the sibling's row is the real lesson) |
| `B_future_misplaced` | same clean conditions, no sibling row, count-mode `auto_recurrence` contract | cancel and re-append the lesson on the contract's own slot via the existing tail-append path (entitlement unchanged) |
| `C_review_template_changed` | the contract's template holds none of its own early slots: edited after start (a legitimate move such as course 448 Tue→Thu), not the #333 union | **no change**; the move is valid |
| `C_review_mode` | clean B row on a date-mode or `manual_occurrence` contract (tail-append is a no-op there) | **no automatic change**; director review |
| `C_past_or_artifact` | past, or a deducted sign-in / learning record / nonzero ledger net | **no automatic change**; director review list (ledger/billing impact) |

Output per contract: the full template tuples `(slot → n, duration)`, the
legacy `week`/`time`, `StartDate`/`EndDate`, `ScheduleMode`/`scheduling_policy`,
own/foreign slots and peers. Summary: `multi_teacher_groups`,
`contaminated_groups`, `contaminated_contracts`, `contaminated_active_contracts`,
`review_only_contracts`, `foreign_sessions_by_bucket`, `by_campus`, and
`contaminated_index` (every student → course IDs, uncapped). Details are capped at
200 groups (`groups_truncated`). For the rest, re-run with the optional
`student_id` input, one student per run, using `contaminated_index`.

Verified locally: the generated probe PHP lints. A throwaway isolated-MariaDB
test seeded one two-teacher group with the bug's union template. It returned 2
contaminated contracts and buckets A=1, B=1, C=1, matching the rules above. The
test was not committed.

## Step 2: manifest (after the probe runs)

From the artifact, write `operations/closeout/inapp-333-template-repair.json`
with these entries:

- one entry per contaminated contract: `course_id`, `before` template,
  `after` = own slots only;
- A/B session IDs with their bucket;
- C IDs as `review_only`.

Pin it to the probe run ID and prod SHA. Any contract whose own slots are empty
or ambiguous (the same slot is "own" for two siblings) goes to `review_only`.

## Step 3: repair (T3, Founder GO on the exact manifest)

Use a guarded repair command in the existing Operations strategy pattern
(dry-run default, `--apply` + `ALLOW_PROD_REPAIR=1`, per-ID allowlist from the
manifest, snapshot before write, idempotent):

1. Rewrite the template as whole `(weekN, timeN, durationN)` tuples plus the
   legacy `week`/`time`, keeping only the own slots with their own durations.
   Clear vacated tuples. Compare-and-set against the manifest `before` snapshot;
   skip if anything changed since the probe.
2. Cancel the A/B rows: `Status=cancelled` plus a `#333-template-repair` Note
   marker. No deletes.
3. For B, call the existing tail-append so the purchased count is preserved.
4. Re-run the probe. Done means `contaminated_active_contracts = 0`, A/B = 0,
   and C unchanged (handed to directors).

Rollback (per-ID, from the snapshot): restore the template tuples and the
legacy `week`/`time`, restore the cancelled rows' statuses, **cancel the
replacement rows that tail-append created** (their IDs are recorded at apply
time), and restore `StudentClass.EndDate`. Steps 1–3 touch no billing, ledger or
sign-in rows.

## Stop points

- After step 1: send counts to the coordinator, then wait for a GO on the
  manifest.
- If C is large, or A/B touches stopped contracts: no repair before a product
  decision.

## Outcome (2026-10-07)

Probe run 37571711459 flagged only course 448. A read-only SELECT by the
coordinator showed it ran on Tuesdays (2/24, 3/3, 3/17), then on Thursdays from
3/26 to 4/23. That is a legitimate move with the template updated, not
contamination. No repair was run. The probe now labels this shape
`template_changed_after_start` and buckets it `C_review_template_changed`.
