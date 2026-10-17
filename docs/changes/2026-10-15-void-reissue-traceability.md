## 2026-10-15 — fix(billing): prepare permanent correction links and receipt-kind replay guard (Refs #4115)
<!-- release-notes: silent_ship=silent-2026-10-15-void-reissue-traceability -->
<!-- silent-reason: Draft backend/schema prerequisite only; the preserved staff UI and announcement are deferred. No production activation is claimed. -->
- Keep one permanent original/replacement/report link; reject incompatible correction kinds, preserve valid receipt replay, and retain history during rollback. Fee-only operations remain a separate disabled implementation.
