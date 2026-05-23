<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $table = 'bank_transactions';

    protected $fillable = [
        'campus_id',
        'transaction_date',
        'amount',
        'reference',
        'description',
        'bank_name',
        'matched_payment_id',
        'reconciled_at',
        'reconciled_by',
        'status',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'reconciled_at' => 'datetime',
        'amount' => 'integer',
    ];
}
