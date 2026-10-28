<?php

namespace App\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * InnoDB rolls a deadlock victim's whole transaction back and expects it to be re-run (#4470). Ledger writers pass
 * ATTEMPTS to their outermost DB::transaction(); Laravel re-runs a closure on a concurrency error only at transaction
 * level 1, so a nested level must let that error through rather than catch it: the server has already rolled back the
 * transaction around it, and later statements would commit one by one.
 */
final class DeadlockRetry
{
    use DetectsConcurrencyErrors;

    public const ATTEMPTS = 3;

    /** The test DB::transaction() uses to decide a re-run (deadlock, serialization failure, lock wait timeout). */
    public static function causedBy(Throwable $e): bool
    {
        return (new self())->causedByConcurrencyError($e);
    }
}
