> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) (I1–I5).

# Refactor Safety Baseline

> Phase 0 artifact. Factual inventory only — no behavior changes.
> Authority: Design Review (2026-06-27) + live code paths.

---

## Purpose

Establish a safety net before introducing shadow domain layers. This document lists critical write paths, services, and current truth sources that golden-lock tests must preserve.

---

## Critical Write-Path Controllers (priority order)

| Controller | LOC (approx) | Write domains |
|---|---|---|
| [`StudentClassController`](../../backend/app/Http/Controllers/StudentClassController.php) | ~5,156 | enrollment, session materialization, payment display, renewal, purchase |
| [`ClassSessionController`](../../backend/app/Http/Controllers/ClassSessionController.php) | ~2,671 | session CRUD, leave, substitute/reschedule, GET auto-materialize |
| [`ScheduleController`](../../backend/app/Http/Controllers/ScheduleController.php) | — | `schedules` exceptions, leave cascade, session write-through |
| [`AttendanceController`](../../backend/app/Http/Controllers/AttendanceController.php) | ~1,228 | sign-in, manual attendance, leave cascade trigger |
| [`LearningRecordController`](../../backend/app/Http/Controllers/LearningRecordController.php) | ~2,838 | LR approve/reject → deduction pipeline |
| [`BillingController`](../../backend/app/Http/Controllers/BillingController.php) | — | invoice/payment, `StudentClass.Paid` sync |
| [`SwipeRfidController`](../../backend/app/Http/Controllers/SwipeRfidController.php) | — | RFID swipe → sign-in → deduction |

---

## Critical Services

| Service | Role |
|---|---|
| [`SessionDeductionService`](../../backend/app/Services/SessionDeductionService.php) | Ledger events + `max()` recompute of UsedSessions/RemainingSessions |
| [`PackageDeductionService`](../../backend/app/Services/PackageDeductionService.php) | Group package shared pool deduction |
| [`ApprovalSessionSyncService`](../../backend/app/Services/ApprovalSessionSyncService.php) | LR approve → synthetic sign-in + session status + deduct |
| [`CourseLeaveCascadeService`](../../backend/app/Services/CourseLeaveCascadeService.php) | Shift+append leave cascade (schedules API path) |
| [`ScheduleGuardService`](../../backend/app/Services/ScheduleGuardService.php) | Teacher/date occupancy conflict validation |
| [`EnrollmentService`](../../backend/app/Services/EnrollmentService.php) | Enrollment orchestration (calls controller for session generation) |

---

## Current Truth Sources (document only — do not fix in shadow phases)

### Sessions (used count)

| Source | Location |
|---|---|
| `StudentSignIn.SessionDeducted` | `SessionDeductionService::batchObservedUsedSessions()` |
| `ClassSession.Status ∈ {completed, attended, late}` | Same + `recomputeCounters()` |
| Orphan approved `LearningRecord` (no ClassSessionID) | Same |
| `session_deduction_ledger` net | `recomputeCounters()` L208–214 |
| Persisted `StudentClass.UsedSessions` / `RemainingSessions` | DB column; index self-heal overrides response |
| Combined rule | `$usedByAttendance = max($attendanceUsed, $ledgerUsed, $classSessionUsed, $lrOrphan)` |

**Read-path self-heal:** `StudentClassController::index` calls `batchObservedUsedSessions` and may override `UsedSessions`/`RemainingSessions` in JSON response without DB write (unless fractional minutes mode).

### Payments

| Source | Location |
|---|---|
| `StudentClass.Paid` | Written one-way from invoice payment (`BillingController::syncStudentClassPaidFromInvoice`) |
| `Invoice` / `Payment` | OR-logic in index: `payment_status = unpaid` only when `Paid=0` AND no invoice payment |
| `AlertController` | Filters on `Paid` column only (ignores invoice-only paid) |
| Display field | `payment_status` computed at read time in index |

### Schedules

| Source | Role |
|---|---|
| `StudentClass.week/time*` | Contract recurrence template |
| `schedules` table | Exception overlay (leave, reschedule, substitute markers) |
| `ClassSession` | Materialized occurrence rows |
| Precedence | No global rule — merged per consumer |

**Five materialization paths (non-unified):**

1. `ScheduleController::ensureClassSessionForScheduleData()`
2. `StudentClassController::ensureMonthlyFutureScheduledSessions()`
3. `ClassSessionController::autoMaterializeTeacherMonthlySessionsForRange()` (GET side-effect)
4. `ClassSessionController::ensureProjected()` (`lockForUpdate`)
5. `StudentClassController::maybeRebuildSessionsAfterUpdate()` (delete + recreate)

---

## Golden API Endpoints (lock in Phase 3+)

| Endpoint | Fields to lock |
|---|---|
| `GET /api/v1/student-classes` | `payment_status`, `UsedSessions`, `RemainingSessions`, `remaining_minutes` |
| `GET /api/v1/student-classes/session-dates` | date lists per course |
| `GET /api/v1/class-sessions` | session rows, by_class grouping |
| `GET /api/v1/alerts/tuition` | alert inclusion + payment_status enrichment |
| `POST /api/v1/attendance` | deduction side effects (Feature tests) |

---

## Existing Regression Tests (delegate — do not duplicate)

| Domain | Tests |
|---|---|
| Sessions | `SessionDeductionMinutesEngineTest`, `LearningRecordApprovalDeductionTest` |
| Payments | `StudentClassPaidStatusTest` |
| Schedules | `ScheduleLeaveCascadeTest`, `SessionDatesRangeFilterTest`, `ClassSessionsTeacherAutoMaterializeMonthlyTest` |

---

## Shadow Layer Namespace (Phase 1+)

```
backend/app/Domain/Scheduling/
backend/app/Domain/Session/
backend/app/Domain/Payment/
backend/app/Application/Command/
backend/app/ReadModel/
backend/app/Monitoring/
```

Config: `backend/config/refactor.php` (Phase 2+, all flags default `false`).

---

## Related Documents

- [`rollback-strategy.md`](rollback-strategy.md)
- [`phase-report-0.md`](phase-report-0.md)
