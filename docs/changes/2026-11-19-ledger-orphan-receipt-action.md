## 2026-11-19 — fix(billing): retain historical bills alongside their receipts
<!-- release-notes: staff_update=staff-2026-11-19-ledger-orphan-receipt-action -->

- In-app #576: retain original non-void bills after a course is removed, using the same invoice student/campus ownership already authorized by the invoice list and slip API. Exclude a live foreign course or foreign live item ownership, mark recovered rows read-only, and keep the existing amount projection and original payment/receipt links. No record, money rule, route, key or role/campus permission changes.
- A receipt still lacking a safe bill/source keeps its anomaly and existing headquarters-report action instead of an impossible withdrawal. Manual review wins when anomaly cards merge. Historical invoice, overpay and zero-bill paths offer no mutation action; ordinary valid-source withdrawal remains available. Stored records are not reassigned or repaired by viewing them, and reporter acceptance remains separate.
