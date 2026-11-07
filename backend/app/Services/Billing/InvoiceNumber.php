<?php

namespace App\Services\Billing;

use App\Models\Invoice;

/**
 * Staff-facing invoice number (INV-YYYYMM-000123). An issued number never changes (in-app #514): when an invoice is
 * re-keyed to another billing_period, its Note carries `[invno_period:YYYY-MM]` with the original period and the
 * number keeps using it. Computed, not stored: no migration.
 */
final class InvoiceNumber
{
    public const TAG = 'invno_period';

    public static function for(Invoice $invoice): string
    {
        $period = preg_match('/\[' . self::TAG . ':(\d{4}-\d{2})\]/', (string) $invoice->Note, $m)
            ? $m[1]
            : ($invoice->billing_period ?: substr((string) $invoice->IssueDate, 0, 7));
        $digits = preg_replace('/[^0-9]/', '', (string) $period);

        return 'INV-' . ($digits ?: 'LEGACY') . '-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
    }
}
