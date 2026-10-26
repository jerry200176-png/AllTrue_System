## 2026-10-26 — migration preflight metadata (#4508 / #4504)
<!-- release-notes: silent_ship=silent-2026-10-26-migration-readonly-preflight -->
<!-- silent-reason: unactivated read-only diagnostic preparation -->

Extend the existing manual, main-only encrypted metadata collector with PHP integer
size, missing CLI extension booleans, primary DB version/session capabilities,
token-table engine/size estimates and a bounded complete migration metadata ledger.
No token/business rows, process arguments, configuration secrets, FPM mutation or
application kernel are read or executed. Existing encryption/shred/readback remain.

The public projection emits counts/digests, never ledger names or arbitrary DB
strings. Compare the ledger against the exact checked-out workflow SHA's migration
tree and the reviewed expiry migration's SHA-256. Missing target, duplicate or
truncated ledger, unsupported profile and extra pending migrations hold. A snapshot
PASS does not prove freshness at execution, DDL duration/cleanup, loaded FPM, or
activation approval; the fixed deployment entry must recheck immediately before DDL.

This candidate neither runs nor authorizes a second production collection. A future
one-time read requires its own exact backend/workflow binding and approved scope.
The current main lacks the unmerged #4508 file, so it cannot pass this scope check.
