## 2026-10-23 — Package ledger probe can list every shared package of a campus
<!-- release-notes: silent_ship=silent-2026-10-23-package-probe-all-packages -->
<!-- silent-reason: operator diagnostic workflow; no product code or data change -->

`inapp-package-ledger-probe.yml` gains `all_packages` (default off): with no package id it lists every shared package of the campus (max 200), not only those whose ledger used more than their total. A package now belongs to the campus by its `campus_id` or through a member course whose student is in the campus. Each package also gets a one-line `summary` (total, ledger used, attended/taught slots, sessions and slots debited twice, debits on lessons that are not chargeable — absent no-shows count as chargeable — and debits without a lesson); the summary is printed right after the target list. Still ids and counts only, one MySQL READ ONLY session rolled back. Refs #4275 (in-app #419: the over-used scan found no package in campus 16).
