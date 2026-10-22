## 2026-10-22 — Payment resolver reads a course's fee evidence once
<!-- release-notes: silent_ship=silent-2026-10-22-free-gift-fee-evidence-once -->
<!-- silent-reason: query-count fix; payment statuses are unchanged -->

`BillingPayableResolver::courseStatus()` decided which terms a 免費／贈送 mark settles by calling `Invoice::freeGiftHolds($course)` for every invoice, and each marked invoice re-ran `Invoice::courseHasFee()` (up to two queries: live priced amendment, positive bill line). Batch reminder and sync runs over courses with many marked terms paid about two queries per term. The resolver now applies the same rule (marked, and the course has no fee on record) with the fee evidence read once per course. Regression: `FreeGiftZeroBillTest::test_fee_evidence_is_read_once_per_course_across_marked_terms`. Rollback is a normal revert. Refs #4017 (item 1).
