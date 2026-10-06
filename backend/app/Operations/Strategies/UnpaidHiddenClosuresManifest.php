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
        // MANIFEST ROWS: read-only probe run 37291794698 (production-case-dump unpaid_hidden_candidates,
        // prod e00d4d097, 2026-10-05): 50 contracts, NT$379000 outstanding.
        return self::$override ?? [
            180 => ['closed_reason' => 'settled', 'outstanding' => 40000, 'campus_id' => 9],
            197 => ['closed_reason' => 'completed', 'outstanding' => 8000, 'campus_id' => 9],
            199 => ['closed_reason' => 'completed', 'outstanding' => 8000, 'campus_id' => 9],
            200 => ['closed_reason' => 'settled', 'outstanding' => 8800, 'campus_id' => 9],
            338 => ['closed_reason' => 'settled', 'outstanding' => 7500, 'campus_id' => 15],
            394 => ['closed_reason' => 'settled', 'outstanding' => 4400, 'campus_id' => 9],
            395 => ['closed_reason' => 'settled', 'outstanding' => 9900, 'campus_id' => 9],
            408 => ['closed_reason' => 'settled', 'outstanding' => 6000, 'campus_id' => 16],
            409 => ['closed_reason' => 'settled', 'outstanding' => 3000, 'campus_id' => 16],
            482 => ['closed_reason' => 'completed', 'outstanding' => 4500, 'campus_id' => 16],
            662 => ['closed_reason' => 'settled', 'outstanding' => 6000, 'campus_id' => 16],
            728 => ['closed_reason' => 'settled', 'outstanding' => 4950, 'campus_id' => 16],
            885 => ['closed_reason' => 'settled', 'outstanding' => 4500, 'campus_id' => 16],
            956 => ['closed_reason' => 'settled', 'outstanding' => 900, 'campus_id' => 9],
            983 => ['closed_reason' => 'completed', 'outstanding' => 1000, 'campus_id' => 4],
            1290 => ['closed_reason' => 'settled', 'outstanding' => 7500, 'campus_id' => 16],
            1321 => ['closed_reason' => 'settled', 'outstanding' => 6000, 'campus_id' => 16],
            1341 => ['closed_reason' => 'completed', 'outstanding' => 7500, 'campus_id' => 15],
            1420 => ['closed_reason' => 'completed', 'outstanding' => 8800, 'campus_id' => 9],
            1437 => ['closed_reason' => 'completed', 'outstanding' => 6000, 'campus_id' => 16],
            1445 => ['closed_reason' => 'settled', 'outstanding' => 7200, 'campus_id' => 9],
            1994 => ['closed_reason' => 'settled', 'outstanding' => 3300, 'campus_id' => 9],
            2004 => ['closed_reason' => 'settled', 'outstanding' => 8000, 'campus_id' => 9],
            2023 => ['closed_reason' => 'settled', 'outstanding' => 13200, 'campus_id' => 9],
            2026 => ['closed_reason' => 'settled', 'outstanding' => 3000, 'campus_id' => 9],
            2027 => ['closed_reason' => 'settled', 'outstanding' => 3000, 'campus_id' => 9],
            2079 => ['closed_reason' => 'settled', 'outstanding' => 6600, 'campus_id' => 9],
            2092 => ['closed_reason' => 'settled', 'outstanding' => 4950, 'campus_id' => 16],
            2101 => ['closed_reason' => 'settled', 'outstanding' => 6600, 'campus_id' => 9],
            2254 => ['closed_reason' => 'settled', 'outstanding' => 8000, 'campus_id' => 11],
            2324 => ['closed_reason' => 'settled', 'outstanding' => 6000, 'campus_id' => 16],
            2539 => ['closed_reason' => 'settled', 'outstanding' => 19800, 'campus_id' => 15],
            2540 => ['closed_reason' => 'settled', 'outstanding' => 8800, 'campus_id' => 15],
            2541 => ['closed_reason' => 'completed', 'outstanding' => 8800, 'campus_id' => 15],
            2709 => ['closed_reason' => 'settled', 'outstanding' => 3300, 'campus_id' => 9],
            2811 => ['closed_reason' => 'completed', 'outstanding' => 22000, 'campus_id' => 15],
            2813 => ['closed_reason' => 'settled', 'outstanding' => 12000, 'campus_id' => 16],
            3255 => ['closed_reason' => 'settled', 'outstanding' => 5400, 'campus_id' => 15],
            3263 => ['closed_reason' => 'settled', 'outstanding' => 5500, 'campus_id' => 3],
            3303 => ['closed_reason' => 'settled', 'outstanding' => 4500, 'campus_id' => 16],
            3361 => ['closed_reason' => 'settled', 'outstanding' => 9000, 'campus_id' => 9],
            3459 => ['closed_reason' => 'settled', 'outstanding' => 6600, 'campus_id' => 15],
            3478 => ['closed_reason' => 'settled', 'outstanding' => 3000, 'campus_id' => 3],
            3488 => ['closed_reason' => 'settled', 'outstanding' => 5500, 'campus_id' => 3],
            3502 => ['closed_reason' => 'settled', 'outstanding' => 8250, 'campus_id' => 16],
            3511 => ['closed_reason' => 'settled', 'outstanding' => 6000, 'campus_id' => 16],
            3516 => ['closed_reason' => 'settled', 'outstanding' => 6600, 'campus_id' => 16],
            3517 => ['closed_reason' => 'settled', 'outstanding' => 6000, 'campus_id' => 16],
            3585 => ['closed_reason' => 'settled', 'outstanding' => 6600, 'campus_id' => 16],
            3587 => ['closed_reason' => 'settled', 'outstanding' => 8250, 'campus_id' => 16],
        ];
    }
}
