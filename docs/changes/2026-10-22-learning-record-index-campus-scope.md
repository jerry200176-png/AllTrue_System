## 2026-10-22 — Learning-record list keeps the campus scope when filtered by course
<!-- release-notes: silent_ship=silent-2026-10-22-learning-record-index-campus-scope -->
<!-- silent-reason: authorization hardening on a read path; same results for the director's own campuses -->

`GET /learning-records` applied the director's campus scope only when no `student_class_ids` filter was given, so a director could list another campus's learning records by passing that campus's course ids. The campus scope now always applies to non-teacher callers below super_admin; `student_class_ids` only narrows it. Teachers keep their own instructor scope, super_admin is unchanged. Regression: `LearningRecordIndexCampusScopeTest`. Rollback is a normal revert.
