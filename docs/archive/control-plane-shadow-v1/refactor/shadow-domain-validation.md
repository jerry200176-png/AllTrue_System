> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) (I1–I5).

# Shadow Domain Validation

> Phase 1 artifact. Maps legacy functions to shadow mirrors and documents known gaps.

---

## Duplication Map (Legacy → Shadow)

| Legacy | Shadow | Status |
|---|---|---|
| `SessionDeductionService::batchObservedUsedSessions()` | `SessionCalculator::calculateObservedUsed()` | Delegates to legacy static (exact) |
| `SessionDeductionService::recomputeCounters()` read portion | `SessionCalculator::calculateUsedByAttendance()` | Verbatim max() mirror |
| `StudentClassController::index` self-heal L384–398 | `SessionCalculator::calculateIndexDisplay()` | Verbatim mirror |
| `StudentClassController::index` payment L412–417 | `PaymentResolver::resolveFromModel()` | Verbatim OR-logic |
| `AlertController::lastPaidAtByStudentClassIds()` | Used by `PaymentResolver` | Direct call (same code path) |
| `StudentClassController::computeEffectiveSessionDates()` | `ScheduleResolver::computeEffectiveSessionDates()` | Verbatim copy |
| `StudentClassController::buildSessionsFromWeeklySchedule()` L4972–4974 | `ScheduleResolver::buildSessionsFromWeeklyScheduleLegacy()` | Includes weekday bug |
| `ClassSession` rows in date range | `ScheduleResolver::materializedSessions()` | Read-only query |
| `schedules` rows in date range | `ScheduleResolver::scheduleExceptions()` | Read-only query |

---

## Known Mismatches (Expected)

| Area | Cause | Action |
|---|---|---|
| Session index vs DB columns | Index self-heal overrides response without DB write | Shadow `calculateIndexDisplay` matches API; DB columns may differ |
| Schedule full merge | Frontend `calendarOccurrenceMerge.js` + `ScheduleGuardService` not mirrored | Documented gap — Phase 3 `ScheduleCalendarView` partial |
| Weekday bug | ISO 1–7 vs Carbon `dayOfWeek` 0–6 in legacy builder | Shadow intentionally replicates bug |
| Alert payment vs index payment | `AlertController` uses Paid-only | Shadow payment resolver mirrors index only |

---

## Validation Commands

```bash
# CLI shadow compare (no production impact)
cd backend && php artisan refactor:shadow-compare

# Single course
php artisan refactor:shadow-compare 12345
```

---

## Instrumentation (Not Wired in Phase 1)

[`ShadowInstrumentation.php`](../../backend/app/Monitoring/ShadowInstrumentation.php) exists but is **not called from controllers**. Gated by `REFACTOR_SHADOW_ENABLED=false`.

Log keys when enabled:
- `SESSION_SHADOW_DIFF`
- `PAYMENT_SHADOW_DIFF`

---

## Related Documents

- [`safety-baseline.md`](safety-baseline.md)
- [`phase-report-1.md`](phase-report-1.md)
