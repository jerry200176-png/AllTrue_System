<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 */
class Payment extends Model
{
    protected static function booted(): void
    {
        // A waived or refunded contract takes no new money movement (the refund itself writes its rows before closing).
        static::creating(function (Payment $row): void {
            $courseId = (int) DB::table('Invoice')->where('id', (int) $row->getAttribute('InvoiceID'))->value('StudentClassID');
            if ($closure = StudentClass::terminalClosureInDb($courseId)) {
                abort(422, $closure === 'refunded' ? StudentClass::REFUNDED_MESSAGE : '此合約已確認不收，不能再登記收款。');
            }
        });
    }

    protected $table = 'Payment';

    // Shared row predicates (void(), ContractMoneyState, the receipt move of #5113). They read Amount / Method /
    // payment_report_id / id, so they take a model or a plain DB row.

    /** A reversal: a negative row or a Method=void row. */
    public static function isReversal(object $p): bool
    {
        return (int) $p->Amount < 0 || (string) $p->Method === 'void';
    }

    /** In-app #521: a reversal already linked to the receipt it reverses (payment_report_id), so the check is exact. */
    public static function reversesReport(object $p, int $reportId): bool
    {
        return self::isReversal($p) && (int) $p->payment_report_id === $reportId;
    }

    /**
     * A legacy unlinked void row (negative or void, no report, not a transfer_out/refund): it cannot be matched to its receipt,
     * so a second reversal might cancel another receipt's money.
     */
    public static function isUnlinkedLegacyReversal(object $p): bool
    {
        return self::isReversal($p) && $p->payment_report_id === null && !in_array((string) $p->Method, ['transfer_out', 'refund'], true);
    }

    /** A receipt's own money row: positive, not void, linked by payment_report_id or (older receipts) by the report's payment_id. */
    public static function isReceiptOf(object $p, int $reportId, ?int $reportPaymentId): bool
    {
        return (int) $p->Amount > 0 && (string) $p->Method !== 'void'
            && ((int) ($p->payment_report_id ?? 0) === $reportId || (int) $p->id === (int) ($reportPaymentId ?? 0));
    }

    protected $fillable = [
        'InvoiceID',
        'Amount',
        'PaidAt',
        'Method',
        'Note',
        'receipt_url',
        'payment_report_id',
    ];
}
