## 2026-10-17 — fix: ignore stale schedules.teacher_id in occupancy (in-app #401/#402)

`ScheduleGuardService::buildTeacherDateOccupancyEntries` skips **normal** schedule rows whose linked `StudentClass.TeacherID` no longer matches the queried teacher (leftover `schedules.teacher_id` after a contract teacher change). Substitute rows (`status=scheduled` + `original_schedule_id`) still count for the substitute teacher so real capacity conflicts are not dropped (#4245 review).

staff_update: silent_ship
