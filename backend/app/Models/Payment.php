<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
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
