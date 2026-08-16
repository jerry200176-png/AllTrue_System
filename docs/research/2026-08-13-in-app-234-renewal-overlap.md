# In-app #234 renewal-overlap evidence (2026-08-13)

## Decision

**Locally verified.** Keep new-contract ClassSession `30421` as the attended lesson and supersede old-contract auto-extension `30430`. The repair may change only the old, unpaid contract because the production read-only scan found no Invoice, InvoiceItem, or Payment row linked to StudentClass `1050`. It must refuse if that fact changes.

## Evidence layers

- **Locally verified:** production read-only workflow `31681553493` found both `30430` (old `1050`) and `30421` (new `3226`) attended at 2026-08-08 15:00; the old session has a deduct ledger event and the new session has its own deduct event. Old contract values were `UsedSessions=8`, `RemainingSessions=0`, `Rate=1100`, `Charge=8800`, `Paid=0`. Existing `SessionDeductionService::reverseForSession()` appends a reversal and `recomputeCounters()` derives balances from the ledger. `BillingController` stores invoices/payments separately from those counters.
- **Documented:** Stripe's [Invoice Line Item object](https://docs.stripe.com/api/invoice-line-item/object) records amount, quantity, pricing, and a service `period` on the line item; it states that the period is used for revenue recognition. This supports refusing to rewrite issued financial documents from mutable session data.
- **Observed:** the current public rendered Stripe API reference exposed the line-item `period`, `pricing`, and `quantity` fields on 2026-08-13. This is public product/documentation behavior only, not evidence of Stripe's private implementation.
- **Source-code verified:** [Kill Bill](https://github.com/killbill/killbill), commit `693a1c5f826253aa67ebc2ff0b409283a0f588fd` (2026-08-05), Apache-2.0, active public project. `beatrix/src/test/java/org/killbill/billing/beatrix/integration/TestIntegrationInvoiceWithRepairLogic.java` exercises invoice repair and item-adjustment behavior. Its source supports recording an adjustment rather than silently altering a financial history. No Kill Bill code is copied.
- **Source-code verified:** [Frappe Education](https://github.com/frappe/education), commit `71aada478bf682f6d034fd4caa6f2f5438b5ace9`, GPL-3.0, active develop branch with public CI/PR activity. `education/education/doctype/course_schedule/course_schedule.py` and `test_course_schedule.py` validate schedule conflicts at write time. This supports AllTrue's invariant that a renewal cannot leave two live lesson projections for the same student/time; no GPL code is copied.

## Local adaptation and limits

**Inferred, then enforced locally.** `repair:renewal-overlap-234` uses fixed evidence IDs and fail-closed preconditions, creates an immutable `session_corrections` audit row and an external JSON snapshot, appends (never deletes) the ledger reversal, recomputes old-course counters, and offers rollback. It voids only the duplicate pending learning record. It preserves the new lesson and all new-contract evidence.

If an invoice/payment is ever present, the command blocks. The future generalized solution is a write-time renewal projection invariant plus financial adjustment documents; this targeted repair deliberately does not claim to solve that broader architecture.
