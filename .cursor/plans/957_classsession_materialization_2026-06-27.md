# EPIC #957 — Unified ClassSession Materialization

> **Tier:** T3 (scheduling / attendance / billing)  
> **Owner:** `[ARCH]` → `[DEV]` backend  
> **Canonical issue:** GitHub #957  
> **Status:** Planned — ready for Phase A branch

---

## Problem

25+ production `ClassSession::create` call sites across 10 files use check-then-insert with **no unique slot index**. Parallel requests and overlapping enrollments produce duplicate or orphaned sessions; calendar, attendance, LR, and billing bind to wrong rows.

**Absorbed issues (closed):** #932, #933, #958, #960, #961, #962, #963, #969, #965

---

## Target architecture

```
StudentClass / schedules (intent)
        ↓
ClassSessionMaterializationService::ensureSlot()
        ↓ lockForUpdate + idempotent upsert
ClassSession (materialized truth)
        ↓
Attendance / LR / Deduction / Calendar (read only via session ID)
```

**Slot key:** `(StudentClassID, SessionDate, StartTime)` — migration adds unique index after cleanup.

---

## Production write sites (migrate in Phase C)

| File | Creates | Priority |
|------|---------|----------|
| `StudentClassController.php` | 8 | P0 |
| `LearningRecordController.php` | 5 | P0 |
| `ClassSessionController.php` | 3 | P0 |
| `EnrollmentService.php` | 2 | P1 |
| `AttendanceController.php` | 2 | P1 |
| `ScheduleController.php` | 1 | P1 |
| `PendingSwipeController.php` | 1 | P1 |
| `CourseLeaveCascadeService.php` | 1 | P2 |
| `CoursePackageController.php` | 1 | P2 |
| `BackfillMissingClassSessionsFromSchedules.php` | 1 | P2 (command) |

---

## Phased delivery

### Phase A — Audit + cleanup command (PR 1)

- [ ] Artisan command `classsession:audit-duplicates` — report duplicate slot keys
- [ ] Artisan command `classsession:merge-duplicates --dry-run` — merge strategy doc in command help
- [ ] Feature test: audit finds injected duplicates
- [ ] **No production behavior change**

### Phase B — Service + unique index (PR 2)

- [ ] `App\Services\ClassSessionMaterializationService`
  - `ensureSlot(StudentClass $sc, Carbon $date, string $start, ?string $end): ClassSession`
  - Uses `DB::transaction` + `lockForUpdate` on StudentClass row
  - Idempotent: return existing if slot exists
- [ ] Migration: unique index on `(StudentClassID, SessionDate, StartTime)` — **after** cleanup verified on staging/Pi backup
- [ ] Unit + feature tests for concurrency (2 parallel ensureSlot → 1 row)

### Phase C — Controller migration (PR 3–5, bounded)

- **PR 3:** `ClassSessionController` + `AttendanceController` → service only
- **PR 4:** `StudentClassController` + `EnrollmentService` → service only
- **PR 5:** `LearningRecordController` + remaining sites

Each PR: grep gate test `ClassSessionMaterializationTest::test_no_direct_create_outside_service` (allowlist service + tests + backfill command).

### Phase D — Downstream fixes (PR 6)

- [ ] `ApprovalSessionSyncService` — bind LR to deterministic slot lookup
- [ ] `SessionDeductionService` — idempotency via unique ledger constraint
- [ ] `calendarOccurrenceMerge.js` — dedupe key includes `student_course_id` (#961 fix)
- [ ] `ScheduleController.destroy` — cascade or soft-delete materialized sessions (#963)

---

## Acceptance criteria

1. Zero `ClassSession::create` in `app/` outside `ClassSessionMaterializationService` (except backfill command until deprecated)
2. Unique index enforced; duplicate audit returns 0 rows on production post-cleanup
3. Regression tests pass: overlap enrollment, count-mode materialize (#937 pattern), LR approval bind, calendar week view (G-007)
4. `docs/CHANGELOG.md` + `AI_REGRESSION_LESSONS.md` updated

---

## Staffing / RACI

| Role | Responsibility |
|------|----------------|
| `[ARCH]` | Slot key definition, migration rollback plan, campus isolation review |
| `[DEV]` | Service + controller migration PRs |
| `[TEST]` | Concurrency tests, golden scenarios § attendance/calendar |
| `[DBA]` | Duplicate cleanup on production backup first; chunkById migration |
| `[REVIEW]` | No new create sites; multi-campus query audit |

---

## Risks

| Risk | Mitigation |
|------|------------|
| Unique index fails on existing dupes | Phase A cleanup mandatory before Phase B migration |
| Package/shared-pool courses (#162) | Explicit out-of-scope per SOP_MATURITY; separate business rule PR |
| Large StudentClassController diff | Split PR 4 by method clusters |

---

## Branch naming

```
feat/957-materialization-phase-a-audit
feat/957-materialization-phase-b-service
feat/957-materialization-phase-c-controllers
```
