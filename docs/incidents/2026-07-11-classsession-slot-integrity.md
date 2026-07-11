# Production Session Closure — ClassSession Slot Integrity (2026-07-11)

Status: **fixes deployed & verified**; systemic remediation (#1161) in this PR; one batch path scoped as follow-up.

## Context

Two infrastructure changes landed 2026-07-10 and each exposed a latent class of bugs:

1. **The Laravel scheduler was wired for the first time.** Scheduled commands in `Kernel.php` had never actually executed before (no working cron driver). Turning it on ran them for real and surfaced bugs no test caught.
2. **The active-only unique index `uq_class_session_slot` (#957 D1) was deployed.** Its generated column is `ActiveSlotFlag = CASE WHEN Status = 'cancelled' THEN NULL ELSE 1 END`, so any raw `ClassSession` date/time write onto an already-occupied non-cancelled slot now throws `SQLSTATE[23000] 1062` instead of silently creating a duplicate.

## What was found, fixed, and verified

| Issue | Root cause | Resolution | Prod status |
|-------|-----------|------------|-------------|
| `reconcile:nightly` crashed 02:00 nightly (`SQLSTATE[42S22] Unknown column 'VoidedAt'`) | Command filtered `ClassSession.VoidedAt`, a column that does not exist (voiding is via `Status`; `VoidedAt` lives on `LearningRecord`/`StudentSignIn`) | Removed the invalid/redundant clause + added `NightlyReconcileTest` | **#1159 merged + deployed** (`whereNull('VoidedAt')` gone; verified on prod) |
| Leave cascade 500s (`1062` on `uq_class_session_slot`, 4× on 07-10 evening) | `CourseLeaveCascadeService::shiftAndAppendAfterLeave` shifted sessions **ascending**, moving an earlier session onto a later one's not-yet-vacated slot | Shift **latest-first** (vacate-ahead); identical end state | **#1160 merged + deployed** (`->reverse()` verified on prod) |
| Reschedule 500s (Sentry PHP-LARAVEL-1Z) | Same class; raw move onto occupied slot | App-layer `422 slot_occupied` guard | **#1150 already deployed**; 07-10 18:46 errors were deploy lag, not a live bug |

The two Sentry issues that reached email (PHP-LARAVEL-20 `1062`, PHP-LARAVEL-21 `VoidedAt`) map exactly to the above and are resolved by deployed code.

## Systemic remediation (#1161, this PR)

Audited **every** `ClassSession` mutation path that can violate `uq_class_session_slot`. Introduced one shared, collision-safe primitive and routed the single-row move paths through it:

- `ClassSessionMaterializationService::findActiveSlotConflict()` — single source of truth for "is this active slot occupied?", mirroring the index exactly (only `cancelled` frees a slot).
- `assertSlotAvailable()` — throws `SlotOccupiedException`, rendered once by the exception handler as **HTTP 422 `slot_occupied`** for API requests (no more opaque 500s).

Paths unified onto the shared primitive:

| Path | Before | After |
|------|--------|-------|
| `LearningRecordController::rescheduleSession` | inline duplicate query (#1150) | shared `findActiveSlotConflict()` (de-duplicated) |
| `ClassSessionController` substitute + change-time | unguarded raw move | `assertSlotAvailable()` → 422 |
| `ClassSessionController::update` (edit time) | unguarded raw move | `assertSlotAvailable()` → 422 |
| `SubstituteController::undo` (revert time) | unguarded raw move | `assertSlotAvailable()` → 422 |

Regression tests use the **real** unique index (`ClassSessionSlotConflictServiceTest`, plus `LeaveCascadeShiftCollisionTest` from #1160), because the bug is invisible without the index enforced. `ClassSessionEditTimeSlotOccupiedTest` covers the endpoint returning 422.

Paths deliberately **not** changed here:

- `StudentClassController` add-session move — already guarded by `detectAddSessionConflict` (returns 409); not double-guarded.
- **Batch contract-realign reflow** (`StudentClassController`) — a bulk reflow whose transient collisions cannot be made safe by a point-check (the swap/mixed-displacement case needs a two-phase move). Never errored in production. **Tracked as the remaining #1161 follow-up** for a dedicated ordered/two-phase fix with its own test — not rushed into this PR.

## Verified nightly automation (#1062)

Forward-generation fired 03:45 (materialized new prepaid sessions), reproduction gate 04:00, business digest 04:10 — all without errors. Only nightly error was the pre-fix reconcile crash.
