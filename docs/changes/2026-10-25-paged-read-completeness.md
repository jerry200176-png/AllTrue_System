## 2026-10-25 — fix(frontend): reject incomplete paged reads
<!-- release-notes: staff_update=staff-2026-10-25-paged-read-completeness -->
- Reject failed or malformed later pages and an exceeded page budget instead of reporting partial course data as a successful source. Preserve scoped requests, array compatibility and bounded concurrency. Refs #4490.
