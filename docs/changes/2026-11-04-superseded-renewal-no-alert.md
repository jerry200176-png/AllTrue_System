## 2026-11-04 — fix(billing): a contract already renewed by a newer one no longer shows 需續課 (in-app #495, #4886)
<!-- release-notes: staff_update=staff-2026-11-04-superseded-renewal-no-alert -->
- `AlertController::newerCourseByStudentClassIds()` counts listed courses as candidates. Its successor is the nearest later course, used for `has_newer_course` and the #1100 overlap check.
- Each row carries `renewed_by_newer_course`: true only when a later same-subject course is priced (Charge or Rate > 0) and not tutoring. Free trials and tutoring are not renewals. `payment_status` is unchanged, so the old contract keeps its 結案 action.
- `studentBillingRows.js` skips a `renewed_by_newer_course` contract for the lessons-left hint and the 需續課 count. It still appears in contracts and money totals.
