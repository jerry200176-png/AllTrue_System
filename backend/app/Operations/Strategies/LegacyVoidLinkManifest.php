<?php

namespace App\Operations\Strategies;

/**
 * Closed allowlist of legacy unlinked void rows (in-app #521): Payment id => the receipt it reversed. IDs only (public repo).
 * Source: read-only case dump inapp_521_swallowed_receipts run 42385247945, 2026-11-07; each row had exactly one candidate receipt
 * with no other linked reversal. Void row 940 (invoice 960) is NOT here: its receipt already has another linked reversal.
 *
 * @phpstan-type Row array{invoice_id:int,report_id:int,payment_id:int,amount:int}
 */
final class LegacyVoidLinkManifest
{
    /** @var array<int,array<string,int>>|null test-only override */
    private static ?array $override = null;

    /** @param array<int,array<string,int>>|null $cases */
    public static function useCasesForTesting(?array $cases): void
    {
        self::$override = $cases;
    }

    /** @return array<int,array<string,int>> */
    public static function cases(): array
    {
        return self::$override ?? [
            773 => ['invoice_id' => 704, 'report_id' => 670, 'payment_id' => 673, 'amount' => -8800],
            1491 => ['invoice_id' => 1485, 'report_id' => 1413, 'payment_id' => 1490, 'amount' => -2500],
        ];
    }
}
