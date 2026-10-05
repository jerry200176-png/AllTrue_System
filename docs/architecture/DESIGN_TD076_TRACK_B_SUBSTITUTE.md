# Design: TD-076 Track B — one live row per occurrence, substitutes included

> **Status:** Draft design, docs only. No code, schema, flag or data change in this PR.
> **Answers:** every item in [`RFC_SCHEDULE_OCCURRENCE_IDENTITY.md`](RFC_SCHEDULE_OCCURRENCE_IDENTITY.md) §10 (items 1–4 and a–f).
> **Date:** 2026-10-05
> **Trigger:** 新莊 session 41612 (R44 recurrence, fixed on the read side by #3539).

## 0. Founder decisions this design needs

Execution needs one combined GO. Each decision below comes with a recommended default.

| # | Decision | Recommended default |
|---|---|---|
| D1 | Pilot campus | 新莊 (CampusID 11). It is where both known risky slots are. |
| D2 | Who taught a past lesson when records disagree | Existing substitute row > non-voided `LearningRecord.TeacherID` > manual `StudentSingIn` > RFID `StudentSingIn`. If two sources disagree, **do not guess**: put the lesson on a director review list. |
| D3 | Retire a duplicate row | New `schedules.status='superseded'`: never live, never deleted, reversible. |
| D4 | Production steps on the pilot | Run in order: dry-run → Jerry checks the digest → execute repair R-1 and R-2 → stamp identity (Phase 3) → turn on the flag for campus 11 only (Phase 4) → watch for one week. |

## 1. Target model

- **Occurrence identity** = (`student_course_id`, `original_schedule_date`, `original_start_time`). It is frozen the first time the occurrence gets any exception: move, substitute, pin, or leave.
- **At most one live row per identity.** Live statuses are `scheduled` and `leave`.
- **Who teaches an occurrence:**
  1. If a live identity row exists, its `teacher_id`.
  2. Otherwise `StudentClass.TeacherID`.

  This holds only after a history pin exists for every taught past occurrence whose teacher differs from the contract (§5, R-2).
- **History** is kept in `schedule_change_log` (append-only). No current-state read uses it.
- **One resolver:** extend `SubstituteScheduleService` (already used by learning records and payroll) with a flag-aware `teacherForOccurrence()`. Every reader in §6 calls it. Do not add a second resolver class.
- **One writer:** a new `OccurrenceAssignmentService`. This is the only new class; it is justified because seven writers currently each build their own chain. Every write runs inside one DB transaction and writes one log row. When the flag is off it delegates to today's code paths unchanged.

## 2. Writers (RFC §10 items 1, c, e)

| Writer | With the flag on |
|---|---|
| `ClassSessionController::substitute` | Find or create the live identity row for the session, set `teacher_id`, write a log row (`reason=substitute`). Leave `ClassSession`, `LearningRecord`, payroll counters and the parent notification as they are today. |
| …the same with `new_date`/`new_start_time`/`new_end_time` | Same transaction: move `ClassSession` and `LearningRecord`, update the live row's date, time and teacher, write **one** log row carrying both the slot change and the teacher change. Undo replays the inverse from that log row. |
| `restoreOriginalTeacherFromSubstitute`, `SubstituteController::undo` | Set the live row's teacher back to the contract teacher, or delete the row if it carried nothing else, and write a log row (`reason=restore`). |
| `TeacherLeaveController::batchSubstitute` | Loop over the same service call. One transaction per lesson, so partial success is visible per lesson, the same as today. |
| `StudentClassController` contract `TeacherID` change (#207 pins) | **Before** changing `TeacherID`, upsert pin rows for taught occurrences using the D2 precedence. Reuse the existing #207 taught-status query. Write a log row (`reason=pin`). |
| `LearningRecordController::updateTeacher` | `update_class=false`: also set the live row's teacher through the service, so the resolver agrees with the corrected learning record. `update_class=true`: take the contract-change path above first. |
| `RescheduleSessionService::execute` | Phase 5: update the live row's slot instead of inserting a chain. It already dual-writes identity behind the flag (`maybeDualWriteOccurrenceIdentity`). |
| `ScheduleController` store/update | Substitute-shaped writes go through the service. `leave` rows keep the current behavior. |

The cutover PR must run the inventory again: `rg original_schedule_id`, plus every write to `TeacherID` / `teacher_id`. The 2026-10-05 inventory missed at least one writer (`RescheduleSessionService` dual-write), so treat it as incomplete.

## 3. Log schema (RFC §10 item b)

Additive migration on `schedule_change_log`:
- new columns: `from_teacher_id` and `to_teacher_id` (nullable, unsigned int)
- new `reason` values: `substitute`, `restore`, `pin`, `repair_supersede`

Existing rows stay as they are (`reason=reschedule`, teacher columns null). Payroll and substitute history can then be audited per occurrence.

## 4. Retire state (RFC §10 item d)

`superseded` is a new `schedules.status` value.

- It is safe because readers use whitelists: backend readers test `scheduled` / `leave` explicitly (`LIVE_STATUSES`, `ClassSessionIndexReadService`), and the frontend merge checks `statusOf(ex) === 'scheduled' | 'rescheduled'`. An unknown status is therefore not live.
- The repair PR must still check each reader in §6 and add a test that a `superseded` row is invisible to each one.
- A retire writes a log row (`reason=repair_supersede`, with from/to status and teacher). The inverse sets the old status back.

## 5. Production repairs (RFC §10 items a, d) — POP catalog operations

Both repairs are POP catalog operations (CONTROL_PLANE_CONTRACT I1). Each one does a dry-run first, writes a digest-pinned manifest, and has an inverse. They are scoped per campus, so the pilot is campus 11 only.

- **R-1, collision keepers.** For every identity (or current slot) that has more than one live row:
  - **Keeper:** the row that matches the live `ClassSession` slot. If several match, the row carrying the latest operator intent: the newest substitute row whose `teacher_id` matches the effective teacher in the session's learning record.
  - **Losers** → `superseded`.
  - **Ambiguous cases** (no session, or a teacher conflict) go to the quarantine list and are not touched.
  - The 2026-10-05 dry-run found 279 collisions across all campuses.
- **R-2, history pins.** Find taught past occurrences with no live row whose D2 evidence teacher is not `StudentClass.TeacherID`. Create a pin row with identity stamped and log `reason=pin`. If the evidence disagrees, quarantine the occurrence. Add a regression for an RFID swipe on a substituted lesson.
- **Then Phase 3 stamp** (`schedules:backfill-occurrence-identity`) on the pilot campus.

## 6. Readers to move to the resolver (RFC §10 item e)

Behind the per-campus flag:
- `ClassSessionIndexReadService` (calendar and 課程查找)
- `SubstituteScheduleService::resolveSubstituteUserId` / `effectiveInstructorUserId`
- `ClassSessionScheduleExceptionReadService`
- `SubstituteService::collectTeacherBusySlots*` (capacity release)
- `ScheduleGuardService`
- `TeacherClassCalendar`
- `StudentClassController` (attendance permission)
- `AttendanceController`
- `FinanceController` part-time payroll
- `LearningRecordController` access and display queries
- `GlobalSearchController`
- frontend `calendarOccurrenceMerge.js` / `calendarExceptionMerge.js`: with the flag on, render the server-resolved teacher and stop walking the chain

The R44 frontend guard from #3539 stays until Phase 6.

## 7. Gates (RFC §10 item f)

Add a new read-only probe case, `occurrence_identity_health`. Per campus, it counts:
1. identity groups with more than one live row, across every live status
2. live non-extra rows with a null or partial identity
3. open quarantine items
4. `substitute_slot_conflicts` (diagnostic only)

**Pilot gate:** counts 1–3 are 0 on campus 11 before the flag goes on, and they stay 0 for one week afterwards. Only then does the next campus start, followed by Phase 5.

## 8. Tests (added in the PRs that change behavior)

- `SubstituteTwoChainsSameSlotParityTest` (#3540): keep it. With the flag on, add a variant where all readers name the substitute from the single live row.
- **Contract change to the substitute** (the Codex case on #3539): readers name the substitute, history unchanged.
- `ContractTeacherChangePreservesHistoryTest` (#207): keep it. Add a variant where the contract was changed before the new writer shipped and R-2 creates the pin.
- **RFID + substitute evidence conflict:** goes to quarantine, no pin.
- `SubstituteWithRescheduleTest`:
  - flag off: unchanged
  - flag on: one live identity row, updated teacher/date/time, one log row, atomic rollback, undo restores
- **`superseded` invisible** to every §6 reader.
- **Null/partial identity:** counted by the health probe and blocks the gate.

## 9. PR sequence

| PR | Content | Tier |
|---|---|---|
| 1 | Log teacher columns + `superseded` constant + reader whitelist tests | R3 (schema) |
| 2 | `OccurrenceAssignmentService` + writers behind the flag (flag off = today) | R3 |
| 3 | Flag-aware resolver + reader migration (§6) | R3 |
| 4 | `occurrence_identity_health` probe | R2 |
| 5 | POP ops R-1 / R-2, dry-run only | R3 |
| — | **Founder GO (D1–D4)** → execute on the pilot → stamp → flag on campus 11 → one-week watch | — |
| 6 | Phase 5 on the pilot: stop chain inserts | R3, needs a separate GO |

## 10. Rollback

- **Flag off for the campus:** readers and writers go back to the chain model. Live identity rows stay valid as chain heads because `original_schedule_id` is kept.
- **R-1 / R-2:** each manifest has an inverse (restore the old status / remove the pins created).
- **Migrations:** additive only (nullable columns, a new status value). No drops before Phase 6 and a second GO.
