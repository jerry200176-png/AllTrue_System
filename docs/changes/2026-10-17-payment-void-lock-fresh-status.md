## 2026-10-17 — fix(billing): payment-report void re-locks fresh status
<!-- release-notes: silent_ship=silent-2026-10-17-payment-void-lock-fresh-status -->
<!-- silent-reason: Internal void race/ownership hardening; no staff-facing fee UI change. -->

Re-lock payment-report void inside the transaction, re-check confirmed status, campus, and ownership before any write. Non-null InvoiceID that cannot be loaded refuses (`void_invoice_mismatch`) and does not clear course Paid; legitimate null-InvoiceID legacy path only when course/report StudentID match. Two-process race evidence on this slice: delayed worker pauses after unlocked confirmed preflight, winner voids, delayed gets fresh 422 — only one void reversal payment.

Follow-up: race fixture student name ≤32 (varchar strict); void treats InvoiceID with strict `!== null` (0 refuses, null legacy retained).

**Lock-order repair (post-Ready Codex P2):** confirm + void now use the same global prefix as `BillingCorrection::applyCountCorrection` — `StudentClass` then `payment_reports` (never report-first). Prior tip `15b319d` source review did **not** cover mixed-writer interleaving; this tip adds a two-process correction×void proof (409 report-locked + single void reversal; deadlock/500 ≠ success). Mixed-writer handshake: void `beforeExecuting` marker on authoritative `StudentClass FOR UPDATE` before parent releases the course-holding correction (not worker-started / sleep). Race workers accept CI `AllTrue_test` and phpunit-isolated `AllTrue_test_[A-Za-z0-9_]+` (≤64), still refuse production / non-loopback.
