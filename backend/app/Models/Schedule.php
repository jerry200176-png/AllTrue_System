<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $student_id
 * @property int|null $teacher_id
 * @property string|null $subject
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property string|float|int|null $duration_hours
 * @property string|null $class_type
 * @property string $status
 * @property string $type
 * @property int $deduction
 * @property int $branch_id
 * @property string|null $schedule_date
 * @property int|null $student_course_id
 * @property int|null $original_schedule_id
 * @property string|null $original_schedule_date
 * @property string|null $original_start_time
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 */
class Schedule extends Model
{
    /** Retired by a TD-076 repair; not live. Readers whitelist 'scheduled' / 'leave', so it is invisible to them. */
    public const STATUS_SUPERSEDED = 'superseded';

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
        'original_schedule_date',
        'original_start_time',
    ];
}
