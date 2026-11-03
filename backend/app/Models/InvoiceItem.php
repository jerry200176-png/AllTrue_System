<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected static function booted(): void
    {
        // A waived (確認不收) contract is terminal: no new billing line may point at it.
        static::creating(function (InvoiceItem $row): void {
            if ($closure = StudentClass::terminalClosureInDb((int) $row->getAttribute('StudentClassID'))) {
                abort(422, $closure === 'refunded' ? StudentClass::REFUNDED_MESSAGE : '此合約已確認不收，不能再建立帳單');
            }
        });
    }

    protected $table = 'InvoiceItem';

    protected $fillable = [
        'InvoiceID',
        'StudentClassID',
        'Description',
        'Amount',
        'PeriodStart',
        'PeriodEnd',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<StudentClass, $this> */
    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }
}
