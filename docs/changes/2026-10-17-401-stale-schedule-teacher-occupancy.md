## 2026-10-17 — fix: ignore stale schedules.teacher_id in occupancy (in-app #401/#402)

Shared provenance helper in `ScheduleGuardService` (used by both `buildTeacherDateOccupancyEntries` and `collectConcreteRecurringOverlaps`): skip only **normal** leftovers whose contract `TeacherID` already moved; keep substitute/reschedule destinations (`original_schedule_id`) and explicit extra/makeup (`type=extra|makeup`) that legitimately keep their own teacher. Teacher-only UI path and teacher+slot edit path share the same invariant (#4245 review).

staff_update: silent_ship
