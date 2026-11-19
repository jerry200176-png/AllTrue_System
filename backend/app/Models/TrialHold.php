<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 試聽暫留 (in-app #575): a teacher's slot reserved for a trial before the student exists.
 * While `held` it occupies the teacher exactly like that trial lesson would (ScheduleGuardService);
 * it creates no student, course, attendance, deduction or bill.
 *
 * @property int $id
 * @property int $campus_id
 * @property int $teacher_id
 * @property \Illuminate\Support\Carbon $hold_date
 * @property string $start_time
 * @property string $end_time
 * @property string|null $note
 * @property string $status
 * @property int|null $converted_student_class_id
 * @property int|null $created_by
 * @property int|null $resolved_by
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class TrialHold extends Model
{
    public const HELD = 'held';
    public const CONVERTED = 'converted';
    public const CANCELLED = 'cancelled';

    protected $table = 'trial_holds';

    protected $attributes = ['status' => self::HELD];

    protected $fillable = [
        'campus_id', 'teacher_id', 'hold_date', 'start_time', 'end_time', 'note', 'status',
        'converted_student_class_id', 'created_by', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'hold_date' => 'date:Y-m-d',
        'resolved_at' => 'datetime',
    ];
}
