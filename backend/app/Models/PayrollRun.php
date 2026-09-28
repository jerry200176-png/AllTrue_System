<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $table = 'payroll_runs';

    protected $fillable = [
        'branch_id', 'month', 'status', 'locked_by', 'locked_at',
        'rule_version_id', 'teacher_rule_snapshots', 'anomalies', 'anomaly_count',
        'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
        'teacher_rule_snapshots' => 'array',
        'anomalies' => 'array',
    ];

    public function lines()
    {
        return $this->hasMany(PayrollRunLine::class, 'run_id');
    }
}
