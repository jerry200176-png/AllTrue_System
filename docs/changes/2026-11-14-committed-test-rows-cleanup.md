## 2026-11-14 — test(infra): shared cleanup for tests that commit rows (#5154)
<!-- release-notes: silent_ship=silent-2026-11-14-committed-test-rows-cleanup -->
<!-- silent-reason: test infrastructure only; no screen, API or data change -->
- Seventeen Feature tests set `connectionsToTransact = []` and commit rows; five never deleted them. The new `CleansCommittedRows` trait records each table's highest auto-increment id in setUp and deletes the rows above it in tearDown, and `CommittedRowsCleanupGuardTest` fails when such a test does not use it.
