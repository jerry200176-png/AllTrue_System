## 2026-11-01 — fix(scheduling): substitute picker uses the guard's one-on-three trial rule
<!-- release-notes: silent_ship=silent-2026-11-01-4368-trial-capacity-read-side -->
<!-- silent-reason: Advisory picker display aligned with the existing write guard; no write-path or data change. -->

The substitute availability endpoint (`SubstituteService::collectTeacherBusySlotsWithCapacity`) classifies a trial row with `ScheduleGuardService::isOneOnThreeSlot()` / `withTrialTargets()` (now public) instead of a fixed capacity of 1, and returns `student_count` and `one_on_three`. `pickerSlotConflict` uses them so a trial covering a one-on-three slot below 3 students is no longer shown full. The reschedule preview and trial makeup paths were already aligned by #4531 and #4532.
