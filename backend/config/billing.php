<?php

return [
    // F7 S5: log (never act on) disagreements between the legacy paid predicate and BillingPayableResolver at outbound sites.
    'paid_status_shadow' => (bool) env('BILLING_PAID_STATUS_SHADOW', true),
    // F7 S5: the tuition notification center decides who owes from BillingPayableResolver (shadow-verified). false = legacy predicate + shadow.
    'paid_status_outbound_notifications' => (bool) env('BILLING_PAID_STATUS_OUTBOUND_NOTIFICATIONS', true),
    // Founder 2026-10-14「不要有折扣功能了」: no new transaction discounts. Deliberately not env-driven;
    // stored snapshots of earlier discounted terms are untouched.
    'transaction_discounts_enabled' => false,
    'receipt_correction_reissue_enabled' => false, // Separate protected activation; never environment-driven.
];
