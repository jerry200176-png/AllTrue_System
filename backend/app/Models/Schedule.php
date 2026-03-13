<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $table = 'schedules';

    protected $fillable = [
        'student_id',
        'teacher_id',
        'subject',
        'day_of_week',
        'start_time',
        'end_time',
        'duration_hours',
        'class_type',
        'status',
        'type',
        'deduction',
        'branch_id',
        'schedule_date',
        'student_course_id',
        'original_schedule_id',
    ];
}
