## 2026-10-17 — fix(billing): payment-report void re-locks fresh status
<!-- release-notes: silent_ship=silent-2026-10-17-payment-void-lock-fresh-status -->
<!-- silent-reason: Internal void race/ownership hardening; no staff-facing fee UI change. -->

Re-lock payment-report void inside the transaction (report → course → invoice → payments), re-check confirmed status, campus, and ownership before any write. Non-null InvoiceID that cannot be loaded refuses (`void_invoice_mismatch`) and does not clear course Paid; legitimate null-InvoiceID legacy path only when course/report StudentID match. Two-process race evidence on this slice: delayed worker pauses after unlocked confirmed preflight, winner voids, delayed gets fresh 422 — only one void reversal payment.

Follow-up: race fixture student name ≤32 (varchar strict); void treats InvoiceID with strict `!== null` (0 refuses, null legacy retained).
