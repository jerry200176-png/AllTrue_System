## 2026-11-04 — fix(billing): a contract already renewed by a newer one no longer shows 需續課 (in-app #495, #4886)
<!-- release-notes: staff_update=staff-2026-11-04-superseded-renewal-no-alert -->
- `AlertController::newerCourseByStudentClassIds()` now counts listed courses as candidates and requires a higher ID, so `has_newer_course` is true when the newer contract is itself in the list. Such a row no longer gets `renew_needed` / `monthly_due_soon` (shown as `paid`); unpaid and partial money statuses are unchanged.
- `studentBillingRows.js` skips a `has_newer_course` contract for the lessons-left hint and the renewing count; it still appears in contracts and money totals.
