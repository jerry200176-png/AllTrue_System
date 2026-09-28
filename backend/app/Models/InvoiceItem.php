<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'InvoiceItem';

    protected $fillable = [
        'InvoiceID',
        'StudentClassID',
        'Description',
        'Amount',
        'PeriodStart',
        'PeriodEnd',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\StudentClass, $this> */
    public function studentClass(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }
}
