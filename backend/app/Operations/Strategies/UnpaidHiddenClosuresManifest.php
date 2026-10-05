<?php

namespace App\Operations\Strategies;

/** Closed allowlist of unpaid contracts hidden as settled/completed (Founder decision 2026-10-05). */
final class UnpaidHiddenClosuresManifest
{
    /** @var array<int,array{closed_reason:string,outstanding:int,campus_id:int}>|null test-only override */
    private static ?array $override = null;

    /** @param array<int,array{closed_reason:string,outstanding:int,campus_id:int}>|null $cases */
    public static function useCasesForTesting(?array $cases): void
    {
        self::$override = $cases;
    }

    /** @return array<int,array{closed_reason:string,outstanding:int,campus_id:int}> */
    public static function cases(): array
    {
        // MANIFEST ROWS: filled from read-only probe run <id>
        return self::$override ?? [
            900000001 => ['closed_reason' => 'settled', 'outstanding' => 1, 'campus_id' => 1],
            900000002 => ['closed_reason' => 'completed', 'outstanding' => 1, 'campus_id' => 1],
        ];
    }
}
