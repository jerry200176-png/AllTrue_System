## 2026-10-18 — test(billing): Bound agreed-fee schema helpers to synthetic databases
<!-- release-notes: silent_ship=silent-2026-10-18-agreed-fee-test-schema -->
<!-- silent-reason: Test-only fixtures and schema safety; no production migration or user-visible capability. -->

- Extract the saved fee fixture/schema helpers without the future API or production extension migration.
- Reuse the existing synthetic schema/loopback allowlist; reject URL, socket, alternate targets and PDO options before schema access or migrate:fresh.
- Refuse DDL inside an open test transaction; retain correction history on destructive rollback attempts.
- Verify safety with mocked unsafe targets and the actual isolated MySQL schema lifecycle in hosted CI.

Rollback is a code revert. Production ALTER, feature enablement, data repair and deployment approval are outside this slice.
