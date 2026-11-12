<?php

/*
 * Bounds for the nightly amount-parity census (`screen_amount_vs_resolver` in `bugs:verify-reproductions`, #4991 item 2).
 * The census is read-only and runs on the Pi, so every bound is a hard stop: when one is hit the result is marked
 * `truncated` and the condition reads INCOMPLETE instead of "0 mismatches".
 */
return [
    'row_cap' => 20000,          // live bills read per run, newest first
    'chunk' => 200,              // bills per chunkById page
    'time_limit_seconds' => 120, // wall clock for the whole census
    'finding_cap' => 200,        // bill ids listed in the JSON detail (the counts are never capped)
];
