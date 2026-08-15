# RFC: Schedule occurrence identity（TD-076 根治計畫）

> **Status:** Draft plan — **not** production execute. Phase 0 inventory filled 2026-08-15. Schema DEV needs Founder GO.  
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

### Live row (sketch — Phase 1 locks names before migrate)

```text
schedules
  id
  student_class_id
  original_schedule_date   -- frozen
  original_start_time      -- frozen
  schedule_date            -- current
  start_time               -- current
  status                   -- live scheduled / cancelled / extra / …
  campus / teacher / room / type …
  UNIQUE (student_class_id, original_schedule_date, original_start_time)
    where live  -- exact unique predicate decided in Phase 1
```

### Log

```text
schedule_change_log
  id, schedule_id or identity tuple
  from_date, from_time, to_date, to_time
  actor_id, reason, created_at
  -- append-only; no updates
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

### Phase 1 — Contract + dual-write design (docs + failing tests for target)

Founder GO on:

- column names
- unique predicate (including cancelled / extra)
- flag name (`schedule_occurrence_v2` or equivalent)
- backfill dry-run shape

Add tests that describe **target** behavior (second reschedule does not add a
live row) marked skipped or behind the flag until Phase 2.

### Phase 2 — Migration + dual write (flag default off)

- Add columns + `schedule_change_log`.
- Writes: still produce chain **and** update identity columns / log (dual
  write) inside `RescheduleSessionService` transaction.
- No read-path switch.
- Rollback: revert deploy; columns stay nullable.

### Phase 3 — Backfill (Repair Manifest; Founder-gated data)

- Dry-run report of chain heads vs identity collisions.
- Founder GO + backup + Repair Manifest.
- Do not run on Pi as “test”.

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

Do not start Phase 2 in a second worktree while Phase 0 is open.

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
4. **Production:** no schema / dual-write / Pi migrate until a later GO. Phase 0 is docs + tests only.

Still open for Phase 1 (not blocking Phase 0):

- Exact unique key vs cancelled extras (extras stay **out** of the identity unique key until a later decision).
- Whether `ClassSession` stores frozen original start or only current.
- Timing vs Laravel 8 (TD-014): **do not combine**.

Until Phase 1 GO, agents may only execute **Phase 0**.

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
| `frontend/src/lib/calendarOccurrenceMerge.js` | yes | R103: skip `scheduled`+`original_schedule_id` with no materialized `ClassSession`. |
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
cd backend && ./vendor/bin/phpunit --filter 'RescheduleSessionPrecisionTest|RescheduleOccupiedSlotTest|RescheduleClassSessionSyncTest|ScheduleStoreOrphanPreventionTest|SubstituteRescheduleRegressionTest|ContractTeacherChangePreservesHistoryTest|AttendanceStatusSemanticsTest|ClassSessionsTeacherVisibilityAfterSubstituteTest|AttendanceLeaveStatusContractTest'
```

| Lock | Bug / rule |
|---|---|
| `scheduleOccurrencePhase0.lock.test.js` + `calendarExceptionMerge.test.js` | R102 ghost boxes SC#2688 / SC#1249 |
| `calendarOccurrenceMerge.test.js` | R103 calendar-only orphan |
| `sessionOccurrenceFilter.test.js` + `AttendanceStatusSemanticsTest` | leave exists, no deduct / no pay |
| `ContractTeacherChangePreservesHistoryTest` | in-app #207 attended teacher lock |
| `ClassSessionsTeacherVisibilityAfterSubstituteTest` | occurrence substitute teacher wins display |
| `sessionConsistency.test.js` | #194 leave_requested visible in attendance + eval |
