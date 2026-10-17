# Billing correction after receipt reversal

silent_ship: protected billing repair prepared as a Draft; not deployed or production verified.

Unpaid-only count/date correction guards now evaluate signed payment history per invoice. Fully reversed receipts with matched report/receipt provenance and exactly one full reversal per report no longer block these corrections; split or extra reversal rows fail closed even when their sum matches the receipt; fractional, malformed or out-of-range report amounts cannot establish an exact match; surviving receipts, partial reversals, nonzero stored PaidAmount and inconsistent negative ledger totals and missing/ambiguous reversal links still block correction. Other callers keep the historical guard. Locked correction paths lock all ledger rows, including reversals, before repeating the guard. Original receipt and reversal rows remain intact. Existing pricing, entitlement and invoice-amount restrictions remain in force.

Payment confirmation and rejection guidance now distinguishes a report's amount from the bill obligation. A conflicting pending report must be checked and rejected/replaced when wrong; an over-limit receipt is retained in the form for reconciliation. The server's invoice cap remains unchanged.

No new schema, linked-reissue behavior, obligation-only correction endpoint or PR4132 dependency is included. Synthetic SQL/controller/component tests verify eligible corrections after full reversal, rejection/replacement before confirmation, selected-invoice caps and partial reversal after preview. Actual process concurrency and production paths remain unverified.
