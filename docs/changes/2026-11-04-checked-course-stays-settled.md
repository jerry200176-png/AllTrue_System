## 2026-11-04 — fix(billing): 已核對的課留在「已結清」清單（#4832）
<!-- release-notes: silent_ship=silent-2026-11-04-checked-course-stays-settled -->
<!-- silent-reason: a rare 已核對 course with a stale invoice flag no longer drops out of the list; nothing new to learn -->

`AccountingController::stoppedCourseVisibleState` keeps a stopped course whose resolver verdict is `paid` with `review_checked` (#4828) in history. Before this, a course with a stale `unpaid`/`partial` invoice flag and `Paid = 0` passed neither the history rule (`closed_reason` had become `settled`) nor the final `Paid = 1` / paid-invoice filter.
