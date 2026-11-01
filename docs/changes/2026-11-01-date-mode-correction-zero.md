## 2026-11-01 — fix(billing): a date-mode 堂數更正 refuses NT$0 when the course Rate is 0 (#4430)
<!-- release-notes: silent_ship=silent-2026-11-01-date-mode-correction-zero -->
<!-- silent-reason: a refusal for an input staff should never send; it reuses the existing count-path message -->
- On a date-mode course with `Rate = 0`, `Rate × count = 0` passed the charge match, so `billingCorrection` rewrote the open bill to an unmarked NT$0 bill, which #3991 forbids. The count path has refused this since #4534. The date-mode path now returns the same `billing_correction_zero_forbidden` refusal (the shared `BillingCorrection::zeroChargeRefusal()`) before any write, and audits it.
