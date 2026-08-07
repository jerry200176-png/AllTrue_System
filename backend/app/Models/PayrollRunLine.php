<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRunLine extends Model
{
    protected $table = 'payroll_run_lines';

    protected $fillable = [
        'run_id', 'class_session_id', 'student_sign_in_id', 'teacher_id', 'session_date',
        'hours', 'base_rate', 'headcount_bonus', 'concurrency_bonus_amount',
        'effective_rate', 'session_salary', 'attendance_status', 'rule_source', 'payload',
    ];

    protected $casts = [
        'session_date' => 'date:Y-m-d',
        'payload' => 'array',
    ];

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
