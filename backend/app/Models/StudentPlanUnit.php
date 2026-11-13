<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 學習計畫單元 (#4970 step 4a): content copied from a template unit.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $sort_order
 * @property string $title
 * @property string|null $note
 * @property int|null $exam_sequence
 * @property \Illuminate\Support\Carbon $planned_start
 * @property \Illuminate\Support\Carbon $planned_end
 * @property bool $dates_manual
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $completed_by
 */
class StudentPlanUnit extends Model
{
    protected $table = 'student_plan_units';

    protected $attributes = ['dates_manual' => false];

    protected $fillable = [
        'plan_id', 'sort_order', 'title', 'note', 'exam_sequence', 'planned_start', 'planned_end',
        'dates_manual', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'planned_start' => 'date:Y-m-d',
        'planned_end' => 'date:Y-m-d',
        'dates_manual' => 'boolean',
        'completed_at' => 'datetime',
    ];
}
