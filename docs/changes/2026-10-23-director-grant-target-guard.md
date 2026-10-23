## 2026-10-23 — A director cannot manage an account that holds a director grant
<!-- release-notes: silent_ship=silent-2026-10-23-director-grant-target-guard -->
<!-- silent-reason: authorization hardening; ordinary teacher accounts are managed as before -->

A merged staff identity keeps `User.type` T but holds a director grant (`user_capability_grants`, capability `director`). The teacher-management target rule (`ProfileController::canManageTeacher()`, `TeacherBranchController::managesTeacher()`) looked only at the type and the campus link, so another director could edit, reset the password of, delete or relink that account. Founder policy 3 (2026-10-22): such an account is a director for account management; below super_admin these routes now return 403 while the grant is unrevoked, with the multi-role flag on or off. A revoked grant makes it an ordinary teacher again; super_admin is unchanged; no data changes. Regression: `DirectorGrantTargetGuardTest`. Rollback is a normal revert.
