## 2026-10-31 — fix(learning-records): edit takes the row lock that a teacher change takes (#4661)
<!-- release-notes: silent_ship=silent-2026-10-31-lr-update-row-lock -->
<!-- silent-reason: concurrency guard only; same screens, same rules -->
- `PUT /learning-records/{id}` now reads the record with `lockForUpdate` inside a transaction and runs its ownership and status checks on that locked row. `AssessmentAuthorSnapshot::sendBackForRefill` takes the same lock. A former teacher's save that races a teacher change therefore either lands first and is captured in the snapshot, or sees the new owner and gets 403. It can no longer refill the new teacher's cleared assessment.
