<?php

/*
 * Known-baseline allowance for `bugs:verify-reproductions` (#4659). An enforced condition fails the nightly job only when
 * its count is ABOVE its value here (absent = 0). null = baseline not measured yet: the count is reported, the job passes.
 * Source of every number: the condition's own count from the nightly run (storage/logs/scheduler-evidence output, echoed by
 * pi-health `scheduler job evidence`), read-only, pinned in the PR that sets it. Lower it as the legitimate cases are fixed.
 */
return [
    'package_over_debit' => null,
    'session_debited_twice' => null,
    'unpaid_charge_vs_rate_count' => null,
    'paid_flag_vs_invoice' => null,
];
