## 2026-11-01 — fix(schedule): schedule writes keep the student slot guard and the student write lock (#4001)
<!-- release-notes: silent_ship=silent-2026-11-01-4001-schedule-write-guards -->
<!-- silent-reason: race and edge-case guards on forced enrollment and schedule edits; no visible behavior change for normal use -->
- A forced `independent_parallel` enrollment still overrides the contract overlap check, but a same-student overlapping slot on another course is refused with the existing 422.
- `ScheduleController::update()` takes the same per-student lock as `store()` and rechecks teacher/room conflicts after it, before save or materialize.
- `store()` refuses (422 `student_course_mismatch`) a payload student that does not own `student_course_id`, and locks the course owner.
