<?php

return [
    // Founder 2026-10-21 (Xinzhuang fee path A): activated in code, never environment-driven. The API still
    // answers 404 for every course outside the campus/course allowlist below, which stays empty unless set
    // through agreed-fee-allowlist-activation.yml. Without the invoice_corrections extension columns,
    // AgreedFeeCorrection::available() stays false (fail closed). Rollback: set this back to false.
    'agreed_fee_correction_enabled' => true,
    // Empty by default. Only the Founder-gated allowlist workflow supplies the approved campus/course IDs.
    'agreed_fee_correction_campus_ids' => array_map('intval', array_filter(explode(',', (string) env('AGREED_FEE_CORRECTION_CAMPUS_IDS', '')))),
    'agreed_fee_correction_course_ids' => array_map('intval', array_filter(explode(',', (string) env('AGREED_FEE_CORRECTION_COURSE_IDS', '')))),
    // F7 S5: log (never act on) disagreements between the legacy paid predicate and BillingPayableResolver at outbound sites.
    'paid_status_shadow' => (bool) env('BILLING_PAID_STATUS_SHADOW', true),
    // F7 S5: the tuition notification center decides who owes from BillingPayableResolver (shadow-verified). false = legacy predicate + shadow.
    'paid_status_outbound_notifications' => (bool) env('BILLING_PAID_STATUS_OUTBOUND_NOTIFICATIONS', true),
    // Founder 2026-10-14「不要有折扣功能了」: no new transaction discounts. Deliberately not env-driven;
    // stored snapshots of earlier discounted terms are untouched.
    'transaction_discounts_enabled' => false,
    'receipt_correction_reissue_enabled' => false, // Separate protected activation; never environment-driven.
];
