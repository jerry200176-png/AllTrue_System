## 2026-10-17 — fix(billing): payment-report void re-locks fresh status (Refs #4247 dep)
<!-- release-notes: silent_ship=silent-2026-10-17-payment-void-lock-fresh-status -->
<!-- silent-reason: Server-side lock/recheck only; directors already see 422 on duplicate void. -->
- Void now re-reads the report under lock (report → course → invoice/payments), re-checks campus ownership, and refuses voided/mismatched invoices — blocks delayed duplicate reversals without changing toast copy from #4244.
