## 2026-10-22 — Bank reconcile checks the target course
<!-- release-notes: silent_ship=silent-2026-10-22-bank-reconcile-target-check -->
<!-- silent-reason: authorization hardening on an API with no screen calling it; nothing staff see changes -->

Reconcile checked the bank transaction's campus but stored any `student_class_id` it was given. It now refuses before any write: 422 (`course_not_found`) when the course does not exist, 403 (`course_campus_mismatch`) when the course's campus (its student's `CampusID`, the rule match suggestions already use) is not the transaction's campus, for every role including super_admin. Regressions in `BankReconciliationTest` (missing course, other-campus course, director with no campus, super_admin). Rollback is a normal revert.
