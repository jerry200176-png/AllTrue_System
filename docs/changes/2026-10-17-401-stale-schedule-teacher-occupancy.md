## 2026-10-17 — fix: ignore stale schedules.teacher_id in occupancy (in-app #401/#402)

`ScheduleGuardService::buildTeacherDateOccupancyEntries` now skips schedule rows whose linked `StudentClass.TeacherID` no longer matches the queried teacher, so a leftover `schedules.teacher_id` after a contract teacher change cannot falsely fill one_on_two capacity.

staff_update: silent_ship
