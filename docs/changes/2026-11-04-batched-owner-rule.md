## 2026-11-04 — fix(schedule): batched substitute lookup uses the same owner rule（#4835）
<!-- release-notes: silent_ship=silent-2026-11-04-batched-owner-rule -->
<!-- silent-reason: the schedule guard stops trusting broken substitute rows, as the single-lesson lookup already does after #4834; nothing new for staff -->

`SubstituteScheduleService::teachersForOccurrences()` (the batched resolver used by the schedule guard) now applies `ownedByTeacher()`. A `schedules` substitute row counts only when its `teacher_id` is empty, the contract teacher, or a user bound to a campus. That is the same rule `occurrence()`, `legacySubstituteUserId()` and the derived SQL got in #4834, and it matches the write guard `SubstituteService::teacherBoundToAny`. Fixture teachers in three tests are bound to their campus, as real teachers are.
