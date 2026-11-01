## 2026-11-01 — chore(probe): package ledger probe splits debits on deleted lessons by course charge (#4740)
<!-- release-notes: silent -->
- `scripts/probes/inapp-package-ledger-probe.php` adds `deleted_lesson_debits` per package: the pool debits on lessons that no longer exist, the part their course ledger still charges (`course_charged`), the part nothing backs (`pool_only`), and how many removals the schedule audit recorded. Read-only, IDs and counts only.
