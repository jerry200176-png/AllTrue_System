## 2026-10-17 — fix: ignore stale schedules.teacher_id in occupancy (in-app #401/#402)
<!-- release-notes: silent_ship=silent-2026-10-17-401-stale-schedule-teacher-occupancy -->
<!-- silent-reason: 待正式 affected-path 驗收及 approved copy；#401/#402 尚未驗證。主任衝突判定會變，暫緩 staff 公告（非永久 no-user-facing）。 -->

Shared provenance helper in `ScheduleGuardService` (used by both `buildTeacherDateOccupancyEntries` and `collectConcreteRecurringOverlaps`): skip only **normal** leftovers whose contract `TeacherID` already moved; keep substitute/reschedule destinations (`original_schedule_id`) and explicit extra/makeup (`type=extra|makeup`) that legitimately keep their own teacher. Teacher-only UI path and teacher+slot edit path share the same invariant (#4245 review).
