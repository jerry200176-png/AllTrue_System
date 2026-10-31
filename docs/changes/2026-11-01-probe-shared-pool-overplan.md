## 2026-11-01 — chore(probe): package ledger probe counts lessons pre-planned past the pool
<!-- release-notes: silent_ship=silent-2026-11-01-probe-shared-pool-overplan -->
<!-- silent-reason: read-only operator probe output; no screen, data or API change -->
- `inapp-package-ledger-probe` adds `future_planned` (counted like `SharedPackagePlanningService`: active members, from today, a group slot once) and `overplanned` (future planned beyond what the pool has left) to each package's summary. Before #4730, each subject pre-planned the full total, and this field finds the plans to review.
