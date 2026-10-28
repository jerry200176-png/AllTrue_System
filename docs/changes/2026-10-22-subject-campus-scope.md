## 2026-10-22 — Campus subjects can only be managed by their campus
<!-- release-notes: silent_ship=silent-2026-10-22-subject-campus-scope -->
<!-- silent-reason: authorization hardening; 科目設定 lists only the director's campus and shared subjects -->

Subject rename, delete and create checked the role only, so a director could rename or delete another campus's subject by id, or create one for another campus via `branch_id`. A campus-specific subject now belongs to its campus: super_admin manages all, other callers only their own campuses (403 otherwise). Shared subjects keep today's rules (rename from 科目設定, delete super_admin only). Regression: `SubjectApiTest::test_a_director_cannot_create_rename_or_delete_another_campus_subject`. Rollback is a normal revert.
