## 2026-10-18 — fix(billing): Serialize invoice voids with their correction owner and recheck invoice ownership
<!-- release-notes: silent_ship=silent-2026-10-18-invoice-void-serialization -->
<!-- silent-reason: Internal transaction ordering and stale-owner checks; no UI, notification, new accounting policy or correction capability. -->

Invoice voids acquire the current header course mutex before locking the invoice;
a headerless single-item invoice uses its sole course.
Resolve header ownership from the current database row rather than a stale route
model, then revalidate header/item owners under the fresh invoice/item locks.
Ownership drift returns409 without mutating a bill or creating a reversal.

Consolidated invoices lock only their header course, preserving the existing
count-correction mutex even though the new fee API rejects shared items. Avoid
introducing a multi-course lock set that can invert existing transfers. Direct
payments retain their existing sorted course locks and gain the same fresh-owner
revalidation.

Independent-process regressions observe the worker locking query in the server
PROCESSLIST and an absent post-query marker while its course is held. Cover
header/item owners, stale route binding, reparenting during the wait and
consolidated header count-correction overlap and void completion while a transfer
owns the other course, in both ID orders.

The regression suite also restores the unique-index test flag after each owning
case, preventing a later remigration from accidentally enabling the gated
index. All original index-enforcement assertions remain.

Rollback: revert code; no migration or account repair. Production activation and
real-case verification remain separate gates.
