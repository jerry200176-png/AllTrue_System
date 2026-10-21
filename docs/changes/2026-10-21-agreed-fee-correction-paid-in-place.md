## 2026-10-21 — feat(billing): agreed-fee correction works in place when the bill already has confirmed money
<!-- release-notes: silent_ship=silent-2026-10-21-agreed-fee-correction-paid-in-place -->
<!-- silent-reason: default-off API behind billing.agreed_fee_correction_enabled; ships with the Founder-gated activation PR #4343 whose staff card covers it -->

Founder decision 2026-10-21: a count-mode course whose bill total is higher than
the agreed fee can now be corrected even after the family paid part or all of
the correct fee. When the single live bill has confirmed net payments that do
not exceed the new fee, the bill total is changed in place to the new fee and
its paid amount/status is recomputed from Payment rows (equal ⇒ paid, less ⇒
partial with the remainder outstanding). Payment and payment-report rows are
never touched; payments above the new fee are refused as `refund_required`;
pending reports, unmatched reversals, stored balance mismatches and the legacy
Paid flag still block. Bills with no money keep the void + reissue path. The
correction history row uses kind `agreed_fee_in_place`.
