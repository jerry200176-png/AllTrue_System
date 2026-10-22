## 2026-10-22 — Learning-record review actions stay within the director's campuses
<!-- release-notes: silent_ship=silent-2026-10-22-learning-record-review-campus-scope -->
<!-- silent-reason: authorization hardening; directors of the record's campus see no change -->

`approve`, `request-changes`, `reject`, `rollback-approval`, `DELETE`, `backdoor-approve`, `batch-reject` and `batch-request-changes` on learning records only checked the caller's role, so a director of another campus could act on a record by id (an approval also adds to the teacher's `TeachingSessionCount`). They now refuse (403) unless the record's student, or the backfilled course's student, belongs to one of the caller's campuses (a batch is refused as a whole if any record is outside), the same rule `update()` and `updateTeacher()` already apply; super_admin keeps access to all campuses. Regression: `LearningRecordReviewCampusScopeTest`. Rollback is a normal revert.
