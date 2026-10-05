<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleChangeLog extends Model
{
    public const REASONS = ['reschedule', 'substitute', 'restore', 'pin', 'repair_supersede'];

    protected $table = 'schedule_change_log';

    public $timestamps = false;

    protected $fillable = [
        'schedule_id',
        'student_course_id',
        'original_schedule_date',
        'original_start_time',
        'from_date',
        'from_time',
        'to_date',
        'to_time',
        'from_teacher_id',
        'to_teacher_id',
        'actor_id',
        'reason',
        'created_at',
    ];
};
