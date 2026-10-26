## 2026-10-18 — test(billing): Adopt legacy nullable correction kinds safely
<!-- release-notes: silent_ship=silent-2026-10-18-agreed-fee-nullable-kind -->
<!-- silent-reason: Synthetic database test helpers only; no production schema or capability change. -->

- Normalize an existing nullable receipt-kind test column before completing the synthetic extension schema.
- Refuse unclassified NULL-kind history before ALTER rather than silently assigning a correction kind.
- Reproduce the receipt test schema with and without its course-key index in lifecycle regressions.

Rollback is a code revert; no production migration, activation or data repair is included.
