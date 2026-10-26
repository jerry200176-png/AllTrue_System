## 2026-10-26 — forward token expiry schema prerequisite (#4504)
<!-- release-notes: silent_ship=silent-2026-10-26-sanctum-expiry-schema -->
<!-- silent-reason: guarded authentication schema candidate; not activated -->

Approved read-only capture 40246976614 confirmed the original 2019 migration is
already recorded while `personal_access_tokens.expires_at` and its index are
absent. Add a new forward migration with a nullable timestamp/index, an index-only
path, and no changes for a complete schema. Check metadata using Laravel 8/12
compatible APIs so this prerequisite can be reviewed separately from #4504.
All structure guards read primary metadata on the connection used for ALTER,
preserving correct behavior with a stale or unavailable read replica.
Preserve all existing token values, abilities and expiry dates; no backfill or
rotation. Missing base tables stop instead of silently recording success.

Guarded `down()` deliberately retains schema and expiry data; roll back code/lock
while keeping the expanded table. Never roll the original table-creating
migration down on Production. Identity-table migrations are outside the automatic
additive exception. No Production DDL/deployment was executed; exact activation,
table-size/DDL-lock risk, FPM/platform evidence and rollback remain release Holds.
