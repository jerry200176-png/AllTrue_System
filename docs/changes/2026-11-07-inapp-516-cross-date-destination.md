## 2026-11-07 — fix(class-sessions): 調到另一天的課可建立可編輯堂次 (in-app #516, GitHub #5056)
<!-- release-notes: staff_update=staff-2026-11-07-inapp-516-moved-lesson -->
- `ensure-projected` now materializes the destination of a cross-date reschedule (anchor row `rescheduled`), even when the requested start is the contract time shown on the phantom chip. Previously it answered 422 「指定日期不符合此課程固定時段」 and the lesson could not be edited or taken attendance. Extra rows and unverifiable anchors are still refused.
- Shared projection (`SessionProjectionReadService::buildProjectedFromEffectiveDates`, used by class-sessions index, student-classes and monthly courses) now labels a non-contract-weekday date with the scheduled row time instead of the contract fallback, so the phantom 20:00 預排 chip shows 17:00.
