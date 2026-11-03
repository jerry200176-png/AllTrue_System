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
