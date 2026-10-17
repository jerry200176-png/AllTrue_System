## 2026-10-17 — ci: Phase-A triage optional max-5 batch (Draft)

Extend `bug-phase-a-triage.yml` with optional `batch_json` (1–5 rows): one `production-activation` Founder gate, validate-all-then-write, fail-stop, exact public-body dedup + post-commit body/hash/status readback, disposition allowlist aligned with `BugReportService::DISPOSITION_KINDS`, ssh failure returns explicit status (no false `wrote` after 255), NUL rejected, unknown JSON keys reported as index+fixed string only. `close_as_logged` forced false (triaged only). Executable extracted-YAML shell tests cover fake-ssh 255 / middle fail-stop. PHPUnit loads the same YAML tinker bytes against AllTrue_test (chunkById >100 oldest hash, case/accent sha256 ≠ unicode_ci, rollback/receipt/retry). No live permission / kill-switch / deploy changes in this PR.

staff_update: silent_ship
