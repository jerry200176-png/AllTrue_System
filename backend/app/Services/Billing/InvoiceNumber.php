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

    /** The Note as people may see it: the number tag is bookkeeping, never shown or exported. */
    public static function visibleNote(?string $note): string
    {
        return trim(preg_replace('/\s*\[' . self::TAG . ':\d{4}-\d{2}\]/', '', (string) $note) ?? '');
    }

    /**
     * The Note with the original-period tag added (a bill that already carries one keeps its first, original tag), or null when the
     * 255-character column has no room. The one place that freezes an issued number before its billing_period changes.
     */
    public static function withTag(string $note, string $period): ?string
    {
        if (preg_match('/\[' . self::TAG . ':\d{4}-\d{2}\]/', $note)) {
            return $note;
        }
        $tagged = trim($note . ' [' . self::TAG . ':' . $period . ']');

        return mb_strlen($tagged) > 255 ? null : $tagged;
    }

    /** Freeze the number on the bill's current period, then set the new one (caller saves). */
    public static function rekey(Invoice $invoice, string $newPeriod): void
    {
        $old = (string) $invoice->billing_period;
        if ($old !== '' && $old !== $newPeriod && preg_match('/^\d{4}-\d{2}$/', $old)) {
            $invoice->Note = self::withTag((string) $invoice->Note, $old) ?? $invoice->Note;
        }
        $invoice->billing_period = $newPeriod;
    }
}
