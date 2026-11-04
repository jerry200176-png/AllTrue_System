## 2026-11-04 — chore(ops): POP operation to remove duplicate history pins over taught past slots
<!-- release-notes: silent_ship=silent-2026-11-04-duplicate-history-pin-repair-pop -->
<!-- silent-reason: reviewed ops data-repair operation; no screen change -->
- New POP operation `duplicate-history-pin-repair-20261104` (#4901, in-app #499) with adapter `pop-duplicate-history-pin-repair.yml`. It detects past slots where the legacy pin pass added a second history pin over an older live pin that already names the sign-in teacher, and deletes exactly those pin and anchor schedule rows (digest-pinned, snapshot, audit, rollback by re-insert). Other disagreements are only listed. Runbook `RM-DUPLICATE-HISTORY-PIN-REPAIR-20261104`.
