## 2026-10-22 — Teacher campus assignment applies the teacher-management target rule
<!-- release-notes: silent_ship=silent-2026-10-22-teacher-branch-target-check -->
<!-- silent-reason: authorization hardening on endpoints the app UI does not call -->

`/teacher_branches` (list, assign, remove) checked only that the caller is a director. Assign and remove now require the target to be a teacher who already has a campus row in one of the caller's campuses (super_admin unchanged), checked before any write; the list shows only the caller's campuses; a server error no longer echoes the exception text. Attaching a teacher the director manages to another campus behaves as before. Regression: `TeacherBranchControllerTest::test_store_and_destroy_refuse_targets_the_director_does_not_manage`. Rollback is a normal revert.
