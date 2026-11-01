## 2026-11-01 — chore(probe): package ledger probe shows whether a package with no course was replaced (#4753)
<!-- release-notes: silent_ship=silent-2026-11-01-probe-packages-without-course -->
<!-- silent-reason: read-only ops probe; no screen, amount or API change -->
- `scripts/probes/inapp-package-ledger-probe.php` adds `packages_without_course`: for each package no course points at, whether the student still exists, how many of the student's later packages a course uses, and how many live courses the student has. Read-only, IDs and counts only.
