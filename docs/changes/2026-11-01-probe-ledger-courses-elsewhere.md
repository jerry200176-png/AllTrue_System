## 2026-11-01 — chore(probe): package ledger probe lists ledger courses that left the package (#4753)
<!-- release-notes: silent_ship=silent-2026-11-01-probe-ledger-courses-elsewhere -->
<!-- silent-reason: read-only ops probe; no screen, amount or API change -->
- `scripts/probes/inapp-package-ledger-probe.php` adds `ledger_courses_elsewhere`: each course a package's ledger names that no longer points at that package, with whether the course row still exists and which package it points at now. Read-only, IDs and counts only.
