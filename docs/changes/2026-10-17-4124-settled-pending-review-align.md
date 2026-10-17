# Settled-courses pending vs tuition review amount (#4124)

silent_ship: billing classification alignment for directors; staff update not required for this internal count consistency.

Stopped contracts whose resolver verdict is `review_required` from an issued invoice that still owes now count as **pending reconciliation** on `/accounting/settled-courses` (amount in `pending_reconciliation_*`) while keeping the payment-review hint. Matches tuition’s findable 金額待確認 row. Review without an issued open balance stays review-only. Does not change `AlertController::tuition` inclusion rules.
