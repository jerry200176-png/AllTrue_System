## 2026-10-18 — fix(billing): Align confirmation locks with course-first money writers
<!-- release-notes: silent_ship=silent-2026-10-18-payment-confirm-course-lock -->
<!-- silent-reason: Internal lock ordering and concurrency regression guard; payment and receipt rules are unchanged. -->

- Preserve the saved #4248 confirmation slice after #4255: lock the contract then the report and verify ownership using the merged shared helper.
- Resolve routing information before the transaction so moving the locks does not create an early REPEATABLE READ snapshot.
- Preserve the two-process 4400 + 4400 settlement regression; use the validated worker bootstrap, parent signing key and MariaDB isolation helper.
- This guards the new lock order; it is not a claim that old main or a real account reproduced the snapshot issue.

Code rollback is a reviewed revert. No migration, activation, account repair, deployment approval or case acceptance is included.
