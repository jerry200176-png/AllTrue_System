## 2026-10-17 — fix(ui): preserve agreed course totals in director view
<!-- release-notes: silent_ship=silent-2026-10-17-course-total-charge-display -->
<!-- silent-reason: Not deployed or production-verified; this candidate contains only a display correction and synthetic tests, with staff announcement deferred until release verification. -->
- Existing count-course totals now prefer the server's effective/agreed charge, preserving discounts, adjustments and explicit zero; missing amounts retain the rate-based estimate.
- Issued invoice totals, payments, outstanding balances and monthly pricing are unchanged. This corrects the course-total display and does not establish the origin or correctness of any particular outstanding balance.
