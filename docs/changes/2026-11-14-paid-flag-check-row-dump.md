## 2026-11-14 — fix(ops): name the rows behind the nightly `paid_flag_vs_invoice` count (#5187)
<!-- release-notes: silent_ship=silent-2026-11-14-paid-flag-check-row-dump -->
<!-- silent-reason: read-only diagnostic case in the dump workflow; no screen, data or nightly threshold change -->
- New read-only case `paid_flag_vs_invoice_rows` in `production-case-dump` lists the contracts the invariant counts: contract id, campus id, side (A paid flag but a bill owes, B unpaid flag but every bill covered), flag value, fully-paid boolean, linked and open bill ids, link type, resolver status, under-review, shared-bill, recount-can-reopen booleans. IDs, enums and booleans only; no names, no amounts. Output stays in the encrypted artifact.
- The invariant SQL moved to `PaidFlagInvoiceMismatch` so the count and the dump share one predicate. Count, baseline and nightly output are unchanged. No repair.
