## 2026-10-18 — fix(billing): Serialize single-course voids and recheck invoice ownership
<!-- release-notes: silent_ship=silent-2026-10-18-invoice-void-serialization -->
<!-- silent-reason: Internal transaction ordering and stale-owner checks; no UI, notification, new accounting policy or correction capability. -->

Single-course invoice voids acquire the course mutex before locking the invoice.
Resolve header ownership from the current database row rather than a stale route
model, then revalidate header/item owners under the fresh invoice/item locks.
Ownership drift returns409 without mutating a bill or creating a reversal.

Consolidated invoices retain invoice-only void serialization: fee correction
already rejects multi-course items. Avoid introducing a new cross-course lock
set that can invert existing higher-ID→lower-ID session transfers. Direct
payments retain their existing sorted course locks and gain the same fresh-owner
revalidation.

Independent-process regressions observe the worker locking query in the server
PROCESSLIST and an absent post-query marker while its course is held. Cover
header/item owners, stale route binding, reparenting during the wait and
consolidated void completion while a reverse-ID transfer owns the higher course.

The regression suite also restores the unique-index test flag after each owning
case, preventing a later remigration from accidentally enabling the gated
index. All original index-enforcement assertions remain.

Rollback: revert code; no migration or account repair. Production activation and
real-case verification remain separate gates.
