## 2026-10-17 — fix(billing): align payment-report pending messages with status labels (Refs #4104)
<!-- release-notes: silent_ship=silent-2026-10-17-4104-payment-report-pending-labels -->
<!-- silent-reason: API toast wording only; directors already see the matching frontend status labels from #4102. -->
- Director payment-report submit, batch submit, and duplicate-pending messages now say 「待你核對入帳」／「已回報付款，待你核對入帳」, matching `courseMoneyState.js`.
