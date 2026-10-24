## 2026-10-22 — Course payment acceptance binds to the clicked course and its badge text
<!-- release-notes: silent_ship=silent-2026-10-22-course-payment-acceptance-binding -->
<!-- silent-reason: acceptance/test hardening plus a data attribute; no visible change -->

The 課程查找 payment cell now carries `data-course-id`. The shared acceptance helper (`frontend/e2e/fixtures/coursePaymentEntry.js`, used by the mocked UI-foundation spec and the production calendar/course acceptance) requires the ledger request's `student_class_id` to equal that id, so "click course A opens course B of the same student" now fails, and it asserts the row's badge text from `courseMoneyState.js` (`coursePaymentBadge`, the same labels the page uses). The mocked spec also pins each state's badge text from the shared constants and adds the #4174 legacy-flag-only case (需核對, never 已收). No assertion was loosened. Rollback is a normal revert. Refs #4186.
