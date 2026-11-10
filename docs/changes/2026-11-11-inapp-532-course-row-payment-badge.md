## 2026-11-11 — fix(course-management): payment status no longer runs under the row buttons (in-app #532)
<!-- release-notes: staff_update=staff-2026-11-11-course-row-payment-badge -->
- On 課程查找 a long 繳費 status (需核對（舊資料標已收，查無收款）) kept its full one-line width (`min-width: max-content`), ran out of its column and sat under 補登收款／管理課程, so staff could not read it.
- The status now wraps inside the 繳費 column. CSS only, scoped to the course row; no label, logic, API or data change.
- `ui-foundation-course-payment-entry.spec.js` asserts for every payment state that the status ends before the row actions start (red before the fix for the legacy-flag state, at 1440 wide).
