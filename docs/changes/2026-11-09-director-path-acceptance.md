## 2026-11-09 — test: private director rollback and student XLSX acceptance
<!-- release-notes: silent_ship=silent-2026-11-09-director-path-acceptance -->
<!-- silent-reason: smoke coverage only; no product screen or business-data behavior change -->
- Add real UI acceptance for cancelling an approved assessment's rollback prompt and downloading a single student's filtered bill workbook, using the existing UI-smoke runtime credentials and serialized job.
- Keep business writes blocked; allow one matching POST to the existing read-only export endpoint, including its export audit. Parse downloads privately with bounded stdlib ZIP/XML checks; report counts only, with no screenshots/traces/video/HTML report or uploaded download.
- Missing credentials/data fail explicitly as unverified. No API, dependency, permission, trigger or production data repair is added; deployed-SHA and business-record acceptance remain separate evidence.
- Execution, privacy and offline verification details: [director path acceptance](../runbooks/director-path-acceptance.md).
