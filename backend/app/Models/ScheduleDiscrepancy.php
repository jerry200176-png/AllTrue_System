<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleDiscrepancy extends Model
{
    protected $table = 'schedule_discrepancies';

    protected $fillable = [
        'class_session_id',
        'reporter_id',
        'branch_id',
        'discrepancy_type',
        'session_date',
        'subject',
        'student_name',
        'time_range',
        'corrected_time_range',
        'notes',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_by',
        'resolved_at',
        'resolution_note',
        'withdrawn_at',
        'archived_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const TYPES = [
        'student_no_show',
        'wrong_student_list',
        'wrong_time',
        'class_cancelled',
        'missing_session',
        'other',
    ];

    public const TYPE_LABELS = [
        'student_no_show'    => '學生未到場',
        'wrong_student_list' => '學生名單有誤',
        'wrong_time'         => '上課時間有誤',
        'class_cancelled'    => '課程已取消',
        'missing_session'    => '此課不在系統中',
        'other'              => '其他',
    ];
}
