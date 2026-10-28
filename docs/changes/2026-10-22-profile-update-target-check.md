## 2026-10-22 — Profile update applies the teacher-management target rule
<!-- release-notes: silent_ship=silent-2026-10-22-profile-update-target-check -->
<!-- silent-reason: authorization hardening; teacher management of a director's own campus teachers is unchanged -->

`PUT /profiles/{id}` now applies the same target rule as reset-password and delete: below super_admin, only teachers the caller manages (`canManageTeacher`) can be updated; any other account returns 403 before any change. super_admin is unchanged. Teacher-management edits of the director's own campus teachers, including multi-campus assignment, behave as before. Regression: `ProfileUpdateTargetTest`. Rollback is a normal revert.
