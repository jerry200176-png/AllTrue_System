<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollMonthStatus extends Model
{
    protected $table = 'payroll_month_status';

    protected $fillable = [
        'branch_id',
        'month',
        'status',
        'locked_by',
        'locked_at',
        'rule_version_id',
        'teacher_rule_snapshots',
    ];

    protected $casts = [
        'locked_at'              => 'datetime',
        'teacher_rule_snapshots' => 'array',
    ];
}
