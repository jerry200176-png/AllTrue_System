<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $schedule_id
 * @property int $student_course_id
 * @property string|null $original_schedule_date
 * @property string|null $original_start_time
 * @property string $from_date
 * @property string $from_time
 * @property string $to_date
 * @property string $to_time
 * @property int|null $from_teacher_id
 * @property int|null $to_teacher_id
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $actor_id
 * @property string $reason
 * @property \Illuminate\Support\Carbon $created_at
 */
class ScheduleChangeLog extends Model
{
    public const REASONS = ['reschedule', 'substitute', 'restore', 'pin', 'pin_conflict', 'repair_supersede'];

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
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'created_at',
    ];
};
