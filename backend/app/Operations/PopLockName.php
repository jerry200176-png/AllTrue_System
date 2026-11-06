<?php

namespace App\Operations;

use Illuminate\Support\Facades\DB;

/**
 * MySQL named locks are server-global, so parallel test workers (one DB each) would collide.
 * Production names stay byte-identical (old/new code must still exclude each other across a deploy);
 * only unit tests get a short per-database suffix (64-char lock-name limit).
 */
final class PopLockName
{
    public static function for(string $name): string
    {
        return app()->runningUnitTests() ? $name . ':' . hash('crc32b', DB::getDatabaseName()) : $name;
    }
}
