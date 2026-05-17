<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $table = 'accounting_periods';

    protected $fillable = [
        'branch_id',
        'period_year',
        'period_month',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'period_year' => 'integer',
        'period_month' => 'integer',
    ];

    public static function isClosed(int $branchId, int $year, int $month): bool
    {
        return self::query()
            ->where('branch_id', $branchId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNotNull('closed_at')
            ->whereNull('reopened_at')
            ->exists();
    }
}
