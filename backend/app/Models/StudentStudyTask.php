<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 學生讀書任務 (#5269 step 1): one thing a teacher asks a student to do by a day.
 *
 * @property int $id
 * @property int $campus_id
 * @property int $student_id
 * @property int|null $plan_unit_id
 * @property string $title
 * @property string $kind
 * @property \Illuminate\Support\Carbon $due_date
 * @property int|null $minutes
 * @property string|null $note
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property int|null $done_by
 */
class StudentStudyTask extends Model
{
    public const KINDS = ['preview', 'homework', 'review', 'correction', 'other'];

    protected $table = 'student_study_tasks';

    protected $attributes = ['kind' => 'other'];

    protected $fillable = [
        'campus_id', 'student_id', 'plan_unit_id', 'title', 'kind', 'due_date', 'minutes', 'note',
        'created_by', 'done_at', 'done_by',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'done_at' => 'datetime',
    ];
}
