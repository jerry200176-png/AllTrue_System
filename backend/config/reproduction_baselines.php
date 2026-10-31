<?php

/*
 * Known-baseline allowance for `bugs:verify-reproductions` (#4659). An enforced condition fails the nightly job only when
 * its count is ABOVE its value here (absent = 0). null = baseline not measured yet: the count is reported, the job passes.
 * Source of every number: the condition's own count from the nightly run (storage/logs/scheduler-evidence output, echoed by
 * pi-health `scheduler job evidence`), read-only, pinned in the PR that sets it. Lower it as the legitimate cases are fixed.
 */
return [
    'package_over_debit' => 109,       // 2026-10-31 04:00 run, pi-health 41157274374
    'session_debited_twice' => 2,
    'unpaid_charge_vs_rate_count' => 0,
    'paid_flag_vs_invoice' => 1,
];
