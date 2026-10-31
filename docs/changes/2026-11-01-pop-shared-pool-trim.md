## 2026-11-01 — chore(ops): POP operation to clean shared pools pre-planned before #4730
<!-- release-notes: silent_ship=silent-2026-11-01-pop-shared-pool-trim -->
<!-- silent-reason: adds a Founder-approved POP operation only; nothing runs until the Founder approves a dry-run digest, and its staff card ships with the closeout -->
- New POP operation `shared-pool-trim-overplan-20261101` (#4734, Founder GO A) with adapter `pop-shared-pool-trim.yml`. It deletes the future `scheduled` lessons that `SharedPackagePlanningService::sharedPoolExcess` lists for the given campuses: nothing points at them, and they sit past what each pool has left. Execute is pinned to the dry-run digest. The snapshot keeps every row for rollback, and every delete is audited. Runbook `RM-SHARED-POOL-TRIM-20261101`.
