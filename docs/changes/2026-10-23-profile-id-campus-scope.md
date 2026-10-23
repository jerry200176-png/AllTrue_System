## 2026-10-23 — fix(staff): reading one staff profile by id is campus-scoped (#4460)
<!-- release-notes: silent_ship=silent-2026-10-23-profile-id-campus-scope -->
<!-- silent-reason: no screen calls GET /profiles?id=; same-campus staff, self and super_admin get the same answer as before -->

- `GET /profiles?id=X` (and `/teachers?id=X`) skipped the campus scope, so any teacher or director could read another campus's staff profile, including `pending_branch_ids`. Below super_admin, `?id=` now uses the list's campus rule, and only the caller's own profile is exempt. An id outside the caller's campuses answers like a missing id: 404 with `single=1`, otherwise an empty list.
- A teacher whose request to join the caller's campus is still pending stays readable, because the 待審核 list already shows them. Regression: `ProfileIndexIdScopeTest`. Rollback is a normal revert.
