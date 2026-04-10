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

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }
}
