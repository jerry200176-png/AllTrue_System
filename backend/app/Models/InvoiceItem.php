<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected static function booted(): void
    {
        // A waived (確認不收) contract is terminal: no new billing line may point at it.
        static::creating(function (InvoiceItem $row): void {
            if (StudentClass::isWaivedInDb((int) $row->getAttribute('StudentClassID'))) {
                abort(422, '此合約已確認不收，不能再建立帳單');
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

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }
}
