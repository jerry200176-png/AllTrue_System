<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'InvoiceItem';

    protected $fillable = [
        'InvoiceID',
        'Description',
        'Amount',
        'PeriodStart',
        'PeriodEnd',
    ];
}
