<?php

return [
    // F7 S5: log (never act on) disagreements between the legacy paid predicate and BillingPayableResolver at outbound sites.
    'paid_status_shadow' => (bool) env('BILLING_PAID_STATUS_SHADOW', true),
    // F7 S5: the tuition notification center decides who owes from BillingPayableResolver (shadow-verified). false = legacy predicate + shadow.
    'paid_status_outbound_notifications' => (bool) env('BILLING_PAID_STATUS_OUTBOUND_NOTIFICATIONS', true),
];
