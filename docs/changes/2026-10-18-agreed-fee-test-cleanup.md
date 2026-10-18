## 2026-10-18 — test(billing): Restore synthetic extensions after normal rollback
<!-- release-notes: silent_ship=silent-2026-10-18-agreed-fee-test-cleanup -->
<!-- silent-reason: Test-only schema lifecycle and isolation; no production behavior or migration. -->

- After Laravel rolls back normal test rows, remove only the synthetic correction extension and verify base nullability and unique indexes before reusing the migration generation.
- Keep complete schema rebuilds for mid-case DDL, partial schemas and committed lifecycle tests; cleanup errors remain visible with the generation invalidated.
- Cover actual teardown row/schema isolation, ordinary refresh without remigration, and target/transaction safety for cleanup.

Rollback is a code revert. CI deadlines, assertions and coverage gates are retained; runtime improvement requires hosted validation.
