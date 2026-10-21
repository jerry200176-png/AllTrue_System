## 2026-10-19 — agreed-fee concurrency regression delivery
<!-- release-notes: silent_ship=silent-2026-10-19-agreed-fee-concurrency-coverage -->
<!-- silent-reason: Test-only extraction of retained #4247 race assertions after API/prerequisites merged; no runtime behavior or feature activation. -->

- Preserve the existing separate-process/PDO race matrix for fee replay, real receipt/report/void writers and invoice issuance, using the shipped isolated-database guard and race-worker lifecycle.
- No production code, schema migration, feature flag, dependencies or workflow changes. Ephemeral test schema remains owned by the existing test helper; the general production fee flag stays false.

- Verify each contender has reached the held-row locking query on the server before releasing its winning peer, and require the authoritative stale-report rejection message. Missing contender SQL and removed status-recheck mutations both fail the strengthened assertions.
- Observe only same-account PROCESSLIST rows; do not require global PROCESS privilege. The held-row FOR UPDATE must remain pending for at least one server-observed second. Isolated schema-only account verification passes while INNODB_TRX is correctly denied.
