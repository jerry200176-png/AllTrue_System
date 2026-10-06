<?php

return [
    // F7 S5: log (never act on) disagreements between the legacy paid predicate and BillingPayableResolver at outbound sites.
    'paid_status_shadow' => (bool) env('BILLING_PAID_STATUS_SHADOW', true),
];
