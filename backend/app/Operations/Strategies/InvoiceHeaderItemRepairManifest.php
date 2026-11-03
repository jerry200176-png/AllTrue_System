<?php

namespace App\Operations\Strategies;

/**
 * Closed allowlist of invoices whose header total and single item amount/course link are stale (GitHub #4850 / #4863).
 * Keyed by invoice id. IDs only (public repo).
 *
 * @phpstan-type Row array{item_id:int,course_id:int,before:array{total:int,item_amount:int,item_student_class_id:int|null,net_paid:int,status:string},after:array{total:int,item_amount:int,item_student_class_id:int}}
 */
final class InvoiceHeaderItemRepairManifest
{
    /** @var array<int,array<string,mixed>>|null test-only override */
    private static ?array $override = null;

    /** @param array<int,array<string,mixed>>|null $cases */
    public static function useCasesForTesting(?array $cases): void
    {
        self::$override = $cases;
    }

    /** @return array<int,array<string,mixed>> */
    public static function cases(): array
    {
        // MANIFEST ROWS: read-only case dumps run 41714126199 and the latest billing_student_numbers run (student 30), 2026-11-04.
        return self::$override ?? [
            1015 => ['item_id' => 46, 'course_id' => 2520,
                'before' => ['total' => 0, 'item_amount' => 0, 'item_student_class_id' => null, 'net_paid' => 8000, 'status' => 'paid'],
                'after' => ['total' => 8000, 'item_amount' => 8000, 'item_student_class_id' => 2520]],
            1016 => ['item_id' => 47, 'course_id' => 2521,
                'before' => ['total' => 0, 'item_amount' => 0, 'item_student_class_id' => null, 'net_paid' => 8000, 'status' => 'paid'],
                'after' => ['total' => 8000, 'item_amount' => 8000, 'item_student_class_id' => 2521]],
            // Payments 2436 (+4400) and 2437 (-4400) net to 0.
            1601 => ['item_id' => 76, 'course_id' => 3379,
                'before' => ['total' => 4400, 'item_amount' => 4400, 'item_student_class_id' => null, 'net_paid' => 0, 'status' => 'unpaid'],
                'after' => ['total' => 5200, 'item_amount' => 5200, 'item_student_class_id' => 3379]],
        ];
    }
}
