## 2026-10-23 — Only super_admin renames a shared subject
<!-- release-notes: silent_ship=silent-2026-10-23-shared-subject-rename-super-admin -->
<!-- silent-reason: authorization hardening; 科目設定 greys out 更名 on shared subjects for directors, like 刪除 -->

Any director could rename a shared subject (CampusID NULL) from 科目設定, and the new name showed in every campus. Founder policy 5 (2026-10-22): only super_admin renames a shared subject; a director gets 403 and the 更名 button on a shared row is disabled with the reason, the same as 刪除. Campus subjects keep their rules (#4418). Existing names are not changed. Regression: `SubjectApiTest::test_only_super_admin_can_rename_a_shared_subject`. Rollback is a normal revert.
