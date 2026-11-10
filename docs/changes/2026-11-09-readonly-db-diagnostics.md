## 2026-11-09 — chore(agents): prepare broad read-only business diagnostics
<!-- release-notes: silent_ship=silent-2026-11-09-readonly-db-diagnostics -->
<!-- silent-reason: review-only DBHub profile, view template and synthetic proof; no live credential, data access or application behavior changes -->
- Prepare pinned upstream DBHub stdio configuration with bounded general SQL and parameterized contract/timeline reads, plus explicit-column view templates for eleven business relationships.
- Keep database-native SELECT-only grants, private TLS routing and verified audit as production activation prerequisites. No triage-key expansion, production connection, schema execution or data repair is performed.
