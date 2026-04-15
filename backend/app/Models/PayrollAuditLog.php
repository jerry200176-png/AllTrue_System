<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollAuditLog extends Model
{
    protected $table = 'payroll_audit_log';
    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'month',
        'action',
        'user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
