## 2026-10-22 — Bank reconciliation stays within the director's campuses
<!-- release-notes: silent_ship=silent-2026-10-22-bank-reconciliation-campus-scope -->
<!-- silent-reason: authorization hardening; directors' own-campus reconciliation is unchanged -->

Only the match-suggestion endpoint checked the transaction's campus. Import (`campus_id` in the body), reconcile (by transaction id) and the list's `branch_id` filter now apply the same rule: super_admin sees every campus, other callers only their own, else 403 before any write. Regression: `BankReconciliationTest::test_a_director_of_another_campus_cannot_import_list_or_reconcile`. Rollback is a normal revert.
