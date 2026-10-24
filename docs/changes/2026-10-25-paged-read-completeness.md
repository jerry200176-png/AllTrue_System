## 2026-10-25 — fix(frontend): reject incomplete paged reads
<!-- release-notes: silent_ship=silent-2026-10-25-paged-read-completeness -->
<!-- silent-reason: Internal loader failure classification; existing caller recovery, screen controls and UI copy are unchanged. -->
- Reject failed or malformed later pages and an exceeded page budget instead of reporting partial course data as a successful source. Preserve scoped requests, array compatibility and bounded concurrency. Refs #4490.
