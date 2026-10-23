## 2026-10-22 — Batch substitute keeps the refusal status of a single substitute
<!-- release-notes: silent_ship=silent-2026-10-22-batch-substitute-keeps-domain-status -->
<!-- silent-reason: response status only; the batch still rolls back and shows the same message -->

`TeacherLeaveController::batchSubstitute` runs each assignment through the single-session substitute and turned any non-2xx answer into a generic 500. When an assessment was approved after the batch precheck, the substitute's own recheck answered 409, but the batch reported 500 「批次代課失敗」. A 4xx answer from the nested substitute now keeps its status (409 here, 422 for an occupied slot); the batch still rolls back as a whole with `rolled_back: true` and the same message, and unexpected errors stay 500. Regression: `ApprovedAssessmentTeacherLockTest::test_batch_substitute_race_after_precheck_returns_409`. Rollback is a normal revert. Refs #3938 (item 3).
