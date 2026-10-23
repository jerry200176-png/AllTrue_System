## 2026-10-22 — Creating staff accounts below super_admin: teachers in the caller's campus only
<!-- release-notes: silent_ship=silent-2026-10-22-profile-store-scope -->
<!-- silent-reason: authorization hardening; 老師管理 creates teachers in the director's campus as before -->

`POST /profiles` accepted `role=director` and any `campus_id` from any director. Below super_admin it now creates teachers only, with the main campus (`campus_id`/`branch_id`) in the caller's campuses, as `bulkTeachers` already requires; super_admin is unchanged. 老師管理 always creates teachers in the director's campus, so its flow is unchanged. Regression: `ProfileStoreScopeTest`. Rollback is a normal revert.
