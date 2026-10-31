## 2026-10-31 — ci(inapp): a refused in-app write-back now fails the run instead of reporting success
<!-- release-notes: silent_ship=silent-2026-10-31-writeback-fail-closed -->
<!-- silent-reason: CI write-back workflow guard only; no screen, API or data change -->
- `php artisan tinker --execute` prints an uncaught exception but still exits 0. So when the follow-up writer refused a row (for example `status changed: in_progress`), run 41187017152 still logged `applied 1 of 1 rows` and went green, and nothing was written.
- `bug-followup-comment.yml` and `bug-phase-a-triage.yml` now end each row's PHP with `WRITEBACK_OK <id>`, and count the row only when that exact line is printed. Anything else fails the job, and the rows written before it are still published. `bug-writeback-workflow.test.mjs` covers the swallowed-throw case.
