# Glossary

## Contract Money Verdict

The single answer to "what does this contract (StudentClass) owe and what has been applied": amount owed, amount applied,
status (free / unbilled / unpaid / partial / paid / review_required), the current billing period, and per-lesson coverage.

**Truth rule (Founder 1A, 2026-10-07):** invoices and their Payment rows decide. The legacy `StudentClass.Paid` flag counts
only as a fallback when the course has no non-void invoice at all. Stored `Invoice.PaidAmount` is never the source of an applied amount.

Implemented by `BillingPayableResolver` + `InvoiceAmountReconciliationService`; `ContractMoneyState` keeps labels only.
Pinned today by `tests/Feature/Billing/ContractMoneyVerdictCharacterizationTest.php`.
