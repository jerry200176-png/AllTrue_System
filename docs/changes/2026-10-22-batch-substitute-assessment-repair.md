## 2026-10-22 — Batch substitute allows the repair that keeps an approved assessment's teacher
<!-- release-notes: silent_ship=silent-2026-10-22-batch-substitute-assessment-repair -->
<!-- silent-reason: rare repair path; aligns batch leave with the single-session substitute rule -->

The batch leave precheck (`TeacherLeaveController::precheckAssignment`) refused every session with a filled and approved assessment, without looking at the requested teacher. The single-session substitute already allows re-asserting the assessment's own teacher as a safe repair (#3824). The batch precheck now compares the requested teacher with the assessment's `TeacherID` and refuses only a real reassignment; the substitute itself still rechecks under lock. Regression: `ApprovedAssessmentTeacherLockTest::test_batch_substitute_repair_that_keeps_the_assessments_teacher_is_allowed`; the refusal stays covered by `test_batch_substitute_is_refused`. Rollback is a normal revert. Refs #3966.
