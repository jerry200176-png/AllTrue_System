# RFC: Schedule occurrence identity（TD-076 根治計畫）

> **Status:** Phase 0–2 merged (`5634e9e9`). Phase 3 = dry-run backfill command (execute gated; not run on production). No read cutover, no stop-chain. §10 (2026-10-05) adds substitute writes to scope.  
> **Date:** 2026-08-15  
> **Campaign card:** [`ALLTRUE_ENGINEERING_NORTH_STAR.md`](ALLTRUE_ENGINEERING_NORTH_STAR.md)  
> **Debt / lessons:** `docs/TECH_DEBT.md` TD-076 · `docs/AI_REGRESSION_LESSONS.md` R102, R103  
> **Related ADR (keep):** [`ADR_004_atomic_reschedule_boundary.md`](../ADR_004_atomic_reschedule_boundary.md)  
> **Issue:** extend GitHub [#1687](https://github.com/jerry200176-png/AllTrue_System/issues/1687) (TD-076); do not open a parallel “rewrite AllTrue” issue.

This RFC is the architecture boundary for Claude Code, Codex, and Cursor.
If a session starts implementing reschedule storage without this file, stop.

---

## 1. Problem

`schedules` models “this lesson moved” as an **immutable chain**:

- insert `status=rescheduled` on the old slot
- insert `status=scheduled` on the new slot
- link with `original_schedule_id`

Readers (backend delete/dedupe, calendar merge, course management) must walk
the whole chain to know **where the lesson is now**. A missed case shows a
dead row as live.

Production (2026-08-08):

| Case | Symptom | Patch (not root) |
|---|---|---|
| 木柵吳艾潼 SC#2688 | duplicate calendar boxes after reschedule-of-reschedule | frontend dedupe (R102) |
| 木柵陳宥翰 SC#1249 / related | same chain shape | frontend dedupe (R102) |
| in-app #225–#227 (true R103) | calendar shows a slot course management denies | frontend skip of orphan destination rows |

Cal.com had the same class of bug on a chain model
([calcom/cal.com#12922](https://github.com/calcom/cal.com/issues/12922)).
RFC 5545 / Google Calendar use a **stable occurrence id** and **PATCH the same
instance**.

---

## 2. Goal

After cutover, for a normal (non-`extra`) occurrence:

1. Identity = `student_class_id` + **original** `schedule_date` + **original**
   `start_time` (frozen at first materialization; never changes).
2. At most **one live** `schedules` row per identity (`status=scheduled` or
   equivalent live state).
3. Reschedule **UPDATE**s that row’s current date/time. It does **not** insert
   a rescheduled+scheduled pair.
4. History goes to append-only `schedule_change_log` (same idea as
   `bug_report_status_logs` vs `bug_reports`). No “current state” query reads
   the log.
5. `RescheduleSessionService::execute()` remains the **only** write boundary
   (ADR-004). Idempotent replay still returns `committed=true` without a
   second live row.

---

## 3. Non-goals

| Do not | Why |
|---|---|
| Rewrite the Vue SPA or Laravel app | Live product; blast radius is billing/attendance |
| Seven-file small-project harness | Wrong scale; INDEX/constitution already exist |
| Drop ADR-004 atomicity | That bug class (half-written reschedule) is separate and fixed |
| Entitlement pooling / StudentClass merge | Course Continuity RFC; different bounded context |
| Change billing, RFID, or auth in the same PR | Unrelated T3 |
| Remove frontend dedupe in the first schema PR | Keep it until cutover evidence exists |
| Production migrate / Pi artisan test | Control-plane + P0 |

---

## 4. Architecture (target)

### Live row (Phase 1 names, locked 2026-08-15)

Identity columns on existing `schedules` (Laravel `student_course_id` = course):

- `original_schedule_date` (date, nullable) — frozen first slot date
- `original_start_time` (string HH:MM, nullable) — frozen first slot start

Current slot remains `schedule_date` / `start_time`. **No UNIQUE index in Phase 2** (collisions until Phase 3 backfill). Extras stay out of any future unique key (Appendix C).

Flag: `FeatureFlag::enabled('schedule-occurrence-v2')` → env `FEATURE_SCHEDULE_OCCURRENCE_V2` (default **false**). Campus override: `FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_{id}`.

### Log

```text
schedule_change_log
  id, schedule_id, student_course_id
  original_schedule_date, original_start_time
  from_date, from_time, to_date, to_time
  actor_id, reason, created_at
  -- append-only; no updates; readers do not query this for current state
```

`ClassSession` still materializes one session per live occurrence. Calendar and
course management must both key off the **identity tuple**, not “latest
schedule id in a chain”.

### Options rejected

| Option | Reject |
|---|---|
| Keep chain, smarter walkers | Already failed twice; Cal.com same shape |
| Frontend-only source of truth | R103: two UIs already diverged |
| Physical merge of StudentClass rows | Course Continuity non-goal D |

---

## 5. Phases (one PR family each; `[INT]` required if split)

Worktrees: `agent-start alltrue <task-id>`. One repo per PR.

### Phase 0 — Inventory (docs + tests, no schema) — **allowed after this RFC merges**

**Owner:** any agent. **Finish:** Draft PR.

Deliverables:

1. Appendix A in this RFC: every write path that inserts `schedules` with
   `rescheduled` / `original_schedule_id` (controllers, services, jobs).
2. Appendix B: every read path that walks `original_schedule_id` or frontend
   chain merge (`calendarExceptionMerge.js`, `calendarOccurrenceMerge.js`,
   course-management session VM).
3. Golden tests that **lock current chain behavior** using R102 fixtures
   (SC#2688 ids in R102; do not invent production rows). Tests must fail if
   someone “simplifies” merge without replacing the model.
4. List of extra/`type='extra'` makeup rows: **out of identity unique key**
   until a later decision (see RFC nonstandard duration). Do not fold extras
   into this unique key in Phase 1.

Commands (adjust to repo scripts; record actual in the PR):

```bash
rg -n "original_schedule_id|status=.rescheduled" backend frontend/src
cd backend && ./vendor/bin/phpunit --filter Reschedule
cd frontend && npx vitest run src/lib/calendarExceptionMerge.test.js src/lib/calendarOccurrenceMerge.test.js
```

### Phase 1 — Contract (names locked 2026-08-15 Founder GO)

Locked:

- column names: `original_schedule_date`, `original_start_time`
- flag: `schedule-occurrence-v2` / `FEATURE_SCHEDULE_OCCURRENCE_V2` default false
- unique predicate: **deferred** (not in Phase 2 migrate)
- backfill dry-run: Phase 3 + Repair Manifest (not this PR)

Target “second reschedule does not add a live row” remains **Phase 5**. Phase 2
only freezes identity on the destination while the chain still grows.

### Phase 2 — Migration + dual write (flag default off) — **this PR family**

- Add nullable columns + `schedule_change_log`. No unique index.
- Writes: still produce chain **and**, when the flag is on, stamp identity on
  the destination + one log row inside `RescheduleSessionService::execute()`.
- Flag off (production default): columns stay null; behavior matches today.
- No read-path switch. Keep frontend R102/R103.
- Rollback: flag stays off; unused nullable columns.

### Phase 3 — Backfill (Repair Manifest; execute still gated)

- Command `schedules:backfill-occurrence-identity` (default dry-run).
- Dry-run: `php artisan schedules:backfill-occurrence-identity` after deploy (no Pi laptop artisan; use a later committed dry-run workflow if needed).
- Execute requires `--force` + `ALLOW_PROD_REPAIR=1` + `I_APPROVE_TD076_OCCURRENCE_BACKFILL=1` + snapshot.
- Do not run execute on Pi until a later GO. Do not enable `FEATURE_SCHEDULE_OCCURRENCE_V2`.

### Phase 4 — Read cutover (flag on in production after smoke)

- Readers prefer live row by identity.
- Calendar and course management must match on R102/R103 fixtures.
- Keep frontend dedupe.

### Phase 5 — Stop chain inserts (flag; Founder GO)

- Writes UPDATE live row + append log only.
- Monitor Sentry / in-app bugs one week.

### Phase 6 — Cleanup (optional)

- Remove redundant frontend chain walkers.
- Drop unused chain columns only after a second Founder GO.

---

## 6. Agent split (suggested, not a second org chart)

| Phase | Typical agent | Artifact the next agent needs |
|---|---|---|
| 0 inventory | Codex or Claude Code | Appendix A/B tables in this RFC |
| 0 goldens | Claude Code / Cursor | vitest + phpunit names + fixtures |
| 1 contract | same as 0 + Founder | decided column list in §4 |
| 2–5 | one implementer + independent review | Draft PR + evidence + unverified list |

Phase 0–2 merged (`5634e9e9`). Phase 3 ships the dry-run/gated command only; do not execute production backfill or start Phase 4/5 without a later GO.

---

## 7. Verification (Definition of Done per phase)

- Campus isolation unchanged (no cross-branch schedule leak).
- ADR-004 tests still pass: atomic commit, occupied slot rollback, idempotent
  replay.
- R102 fixtures: one visible occurrence per identity in **both** calendar merge
  and course-management VM.
- No production SHA claimed from CI green alone (`version.json` after deploy).
- CHANGELOG + silent_ship or staff_update per `GUIDE_STAFF_UPDATES.md`.

## 8. Rollback

- Phase 0–1: revert git.
- Phase 2: flag off; dual write unused by readers.
- Phase 4: flag off; readers back to chain (frontend dedupe still present).
- Phase 3 backfill: Repair Manifest inverse; do not hand-edit Pi rows.

## 9. Founder answers (2026-08-15) + remaining Phase 1 questions

Answered (lock these; do not “simplify” in a later PR):

1. **Leave:** the occurrence **still exists**. Status is leave. It **does not deduct a purchased session and does not count as billed money** (`AttendanceStatus` `leave` is `deductible=false`, `payable=false`; `rowOccupiesPurchasedQuota` is false).
2. **Substitute teacher:** the teacher **on that occurrence** (schedule pin / that session’s substitute row), not the contract teacher on `StudentClass`.
3. **Past attendance:** once a session is attended, **lock the teacher who was there**. Changing the contract teacher must not rewrite history (`ContractTeacherChangePreservesHistoryTest`, in-app #207).
4. **Production:** Founder GO 2026-08-15 for **Phase 1+2 only** if live product is not broken. Flag stays **off**. No Phase 3 backfill, Phase 4 read cutover, or Phase 5 stop-chain without a later GO. No Pi artisan as “test”.

Still open (not blocking Phase 2):

- Exact unique key vs cancelled extras (extras stay **out** of the identity unique key until a later decision).
- Whether `ClassSession` stores frozen original start or only current.
- Timing vs Laravel 8 (TD-014): **do not combine**.

---

## 10. Substitute scope (added 2026-10-05)

**Trigger:** 新莊 session 41612 (2026-10-09 10:00). 課程查找 showed the substitute, but the calendar kept the contract teacher. Data evidence:
- probe run 37300311383 (`substitute_calendar_xinzhuang`)
- monitor run 37302577020 (`substitute_slot_conflicts`)

One slot held two chains:
- 12695→12696: an older reschedule *onto* this slot, contract teacher
- 12697→12698: the substitute

The substitute write did not find the live reschedule target. `ClassSessionController::substitute` looks for anchors only on the session's own date, and 12695 is on another date. So it created a second chain. The monitor found exactly this shape in every risky slot (2 slots, both 新莊, both reschedule-then-substitute).

**Why the chain model cannot be patched on the read side:** the same data shape needs opposite answers.
- **R44:** a newer stale contract-teacher row must lose to the substitute.
- **Contract change to the substitute** (Codex on #3539): the newer current-contract row must win.

Any reader-side tie-break (newest id, or prefer non-contract) gets one of them wrong. Only one live row per occurrence removes the tie.

**Decision (target, same flag and phases as §5):**
1. A substitute is an **UPDATE of `teacher_id`** on the occurrence's single live row, plus one `schedule_change_log` row (`reason='substitute'`). It no longer inserts a `rescheduled`+`scheduled` pair. "Restore contract teacher" is the same UPDATE back.
2. Writers in scope (Appendix A): `ClassSessionController::substitute` and `restoreOriginalTeacherFromSubstitute`, the `StudentClassController` substitute pin (#207 history pins), `TeacherLeaveController::batchSubstitute`, `SubstituteController::undo`.
3. One resolver: "who teaches this occurrence" = the live row's `teacher_id`, else `StudentClass.TeacherID`.
   - Backend readers stop doing `MAX(id)` over substitute rows: `ClassSessionIndexReadService`, `SubstituteScheduleService::effectiveInstructorUserId`, attendance, payroll.
   - The frontend never re-derives the teacher from `schedules` (R44, 2026-10-05 clause).
4. Interim, before Phase 5, behind the same flag: when a substitute targets a slot that already has a live `scheduled` row with `original_schedule_id` (a reschedule target), update that row's teacher. Do not create a second chain. This alone removes the shape behind every slot the monitor flagged.

**Evidence of scale (2026-10-05, read-only):**
- Phase 3 dry-run run 37301540539: stampable 5302, collisions **279** (230 on 2026-09-02), superseded 358, drift 0.
- Slot-conflict monitor: 2 calendar-risk slots in today−7..+60.

**Must be designed before the GO (Codex reviews on #3542 / #3550; an open list, not exhaustive — the Track B design PR must answer every item and re-run the inventory):**

a. **History pins (#207).** An attended occurrence with no schedules row has no live row to UPDATE.
   - Before a contract-teacher change, upsert the stable occurrence row with the teacher who taught. Otherwise the resolver falls back to the new `StudentClass.TeacherID` and rewrites past attendance and payroll.
   - Keep `ContractTeacherChangePreservesHistoryTest` green.
   - **Already-changed contracts:** Phase 3 backfill only reads existing schedules rows, so it cannot create a missing pin. Before cutover on a campus, inventory attended/taught `ClassSession`s with no schedules row whose taught teacher (`StudentSingIn` / `LearningRecord` evidence) differs from the current `StudentClass.TeacherID`. Repair them by creating stable rows from that evidence (Repair Manifest, dry-run first). Add a regression for a contract that was changed before the new writer shipped.
   - **Evidence precedence:** `StudentSingIn.TeacherID` from RFID swipes (`SwipeRfidController`) records the contract teacher even on substituted lessons, while `LearningRecordBackfillService` resolves the substitute. They are not interchangeable. Define one precedence (proposal: existing substitute schedules row > non-voided `LearningRecord.TeacherID` > manual-attendance `StudentSingIn` > RFID `StudentSingIn`). Quarantine rows where the sources disagree, for director review, and do not auto-pin them. Add a regression for RFID swipe + substitute.

b. **Teacher history.** Add `from_teacher_id` / `to_teacher_id` to `schedule_change_log`, so an UPDATE never loses who was replaced or restored. Substitute and payroll history must stay auditable.

c. **Substitute + reschedule stays atomic.** The `new_date`/`new_start_time`/`new_end_time` path moves `ClassSession` and `LearningRecord`, sets the teacher and writes the notification in one transaction, and undo restores the time.
   - The new boundary covers the combined operation and its undo.
   - `SubstituteWithRescheduleTest` currently asserts the chain pair (`rescheduled` + `scheduled`). Under the flag, replace those storage-shape assertions with: exactly one live identity row, updated teacher/date/time, one audit entry, atomic rollback, and undo restores. Keep the flag-off case as today. Do not weaken it to make it pass.

d. **Repair existing collisions first.** Phase 3 backfill skips colliding rows, and the interim write rule only prevents new ones.
   - Collisions need a per-slot repair manifest. The keeper is the row matching `ClassSession` plus the latest operator intent. Each loser gets an explicit **non-live state transition on the `schedules` row itself**: proposal `status='superseded'`, a status every live reader already excludes; this must be verified per reader. Each transition writes one audit entry (old status/teacher/slot → superseded), and the manifest's inverse restores the old status. The append-only log alone cannot retire a row.
   - The pilot campus needs **zero unresolved collisions** before identity readers are switched on there. This includes the two known 新莊 slots.

e. **Migrate every substitute reader.** These still treat `original_schedule_id IS NOT NULL` as "is a substitute" and must use the one resolver:
   - `SubstituteService::collectTeacherBusySlots*` (capacity release)
   - `TeacherClassCalendar`
   - `StudentClassController`
   - `GlobalSearchController`
   - the learning-record queries
   - attendance and payroll

   Writers too, not just readers: `LearningRecordController::updateTeacher` (with `update_class=false` it changes `LearningRecord.TeacherID` and payroll counters only; with `true` it can reattribute unpinned history), and every `StudentClass.TeacherID` mutation. These must route through the resolver writer, with each one in the regression matrix.

   Re-run the Appendix A/B inventory. Use `rg original_schedule_id` **and** `rg "TeacherID"` writes, because grep on the chain column misses teacher writers. List each reader and writer in the cutover PR.

f. **Acceptance metric.** The gate is a **campus-scoped duplicate count over the frozen identity columns** (`student_course_id`, `original_schedule_date`, `original_start_time`) across **every live status** (scheduled, leave, …). It must be 0. That needs a new read-only monitor.

   It must also show **complete coverage**: every non-extra live row on the campus has both identity columns set. Zero duplicates alone does not prove this, because unstamped collision keepers and new pins would be invisible to identity readers. Add a null/partial-identity regression.

   `substitute_slot_conflicts` is diagnostic only and is not the gate. It groups by the current slot, counts only `scheduled` rows, and needs different teachers, so it misses same-teacher, moved-slot and `leave` duplicates. Its `calendar_risk_slots` is a symptom metric too.

**Gate:** items 1–4 and a–f need the same Founder GO as Phase 4/5. The pilot campus is 新莊 (CampusID 11). Until then the R44 frontend guard (#3539) keeps the calendar equal to 課程查找, and the monitor case `substitute_slot_conflicts` is the regression signal.

**Acceptance:** the identity duplicate count (item f) = 0 on the pilot campus for one week after cutover. A parity test passes: calendar, course management, attendance and payroll all name the same teacher, both for the 41612 shape and for the contract-change shape.

---

## Appendix A — Write paths (Phase 0, 2026-08-15)

Inventory: `rg -n "original_schedule_id|status=.rescheduled" backend/app`.

| Path | Inserts chain? | Notes |
|---|---|---|
| `RescheduleSessionService::execute()` | **yes** | ADR-004 atomic writer: creates `rescheduled` anchor + `scheduled` destination with `original_schedule_id`. Must remain the only **product** reschedule API. |
| `ScheduleController::store()` | **yes** | Generic exception store. R102: same-slot duplicate-delete may miss a live `scheduled` row. Also creates `status=leave` (`deduction=0`) without `original_schedule_id`. |
| `ClassSessionController` (substitute / move) | **yes** | Can `Schedule::create` a `rescheduled`+`scheduled` pair when pinning a substitute onto a moved session. Same ghost-row delete pattern as `ScheduleController`. |
| `StudentClassController` (substitute display pin) | **yes** | Writes `rescheduled` + destination `original_schedule_id` so calendar prefers the substitute teacher for that slot. |
| `SubstituteController` | reads/updates chain | Looks up `rescheduled` + `original_schedule_id`; must not invent a second live destination. |
| `AttendanceController` leave path | **no chain** | `Schedule::create` `status=leave`, `deduction=0`. Occurrence stays; no purchased-session deduct. |
| `PaymentReportController` / `ParentPortalController` | read only | Treat `rescheduled` as a session status in some lists — do not add writes. |
| `LearningRecordController` | read substitute pin | Joins `schedules.original_schedule_id` to show who taught; must keep attended lock. |

## Appendix B — Read / merge paths (Phase 0, 2026-08-15)

| Path | Walks chain? | Notes |
|---|---|---|
| `frontend/src/lib/calendarExceptionMerge.js` | yes | R102 same-slot supersede (not same-date-only). Leave on that course/date hides the scheduled exception card. |
| `frontend/src/lib/calendarOccurrenceMerge.js` | yes | R103: skip `scheduled`+`original_schedule_id` with no materialized `ClassSession`. R44 (2026-10-05, 新莊 session 41612): two chains on one slot overlaid in turn; the contract-teacher row overwrote the substitute. Now a backend-resolved `substitute_teacher_id` on the session row wins. |
| `frontend/src/lib/sessionDates.js` | yes | Treats `scheduled`+`original_schedule_id` as a reschedule destination. |
| `frontend/src/composables/course-management/useRescheduleAndMakeup.js` | yes | Splits leave vs destination lists. |
| `frontend/src/pages/SmartCalendar.vue` | yes | Uses `original_schedule_id` when matching a cell. |
| `frontend/src/lib/sessionOccurrenceFilter.js` | no | Shared “effective session”; `leave` does not occupy quota. |
| `frontend/src/lib/sessionConsistency.js` | no | Attendance vs eval labels (`leave_requested` #194). |
| `SubstituteScheduleService` / `SubstituteService` | yes | Live substitute = `scheduled` + `original_schedule_id` + other teacher. |
| `ClassSessionController::index` teacher join | yes | Derived-table `MAX(id)` of substitute rows (TD-058). |

## Appendix C — Extra / makeup rows (out of identity unique key)

`schedules.type='extra'` makeup rows are **not** the original occurrence. Phase 1 unique key must not fold them in. They remain a separate live row until a later RFC decision (nonstandard duration / makeup identity).

## Appendix D — Regression lock commands (do not drop)

These must stay green. They encode old production bugs; “simplifying” merge to make TD-076 easier is a regression.

```bash
cd frontend && node src/lib/scheduleOccurrencePhase0.lock.test.js
cd frontend && npm run test:calendar
cd backend && ./vendor/bin/phpunit --filter 'RescheduleSessionPrecisionTest|RescheduleOccupiedSlotTest|RescheduleClassSessionSyncTest|ScheduleStoreOrphanPreventionTest|SubstituteRescheduleRegressionTest|ContractTeacherChangePreservesHistoryTest|AttendanceStatusSemanticsTest|ClassSessionsTeacherVisibilityAfterSubstituteTest|AttendanceLeaveStatusContractTest|ScheduleOccurrenceDualWriteTest|BackfillScheduleOccurrenceIdentityTest'
```

| Lock | Bug / rule |
|---|---|
| `scheduleOccurrencePhase0.lock.test.js` + `calendarExceptionMerge.test.js` | R102 ghost boxes SC#2688 / SC#1249 |
| `calendarOccurrenceMerge.test.js` | R103 calendar-only orphan |
| `sessionOccurrenceFilter.test.js` + `AttendanceStatusSemanticsTest` | leave exists, no deduct / no pay |
| `ContractTeacherChangePreservesHistoryTest` | in-app #207 attended teacher lock |
| `ClassSessionsTeacherVisibilityAfterSubstituteTest` | occurrence substitute teacher wins display |
| `sessionConsistency.test.js` | #194 leave_requested visible in attendance + eval |
| `ScheduleOccurrenceDualWriteTest` | Phase 2: flag off = null identity; flag on = frozen identity + one log per execute; chain still exists |
| `BackfillScheduleOccurrenceIdentityTest` | Phase 3: two-hop live head identity; extras/ghosts skipped; collisions not stamped; production execute gated |
