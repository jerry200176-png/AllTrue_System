<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 學生學習計畫 (#4970 step 4a): one non-archived plan per (student, course).
 *
 * @property int $id
 * @property int $campus_id
 * @property int $student_id
 * @property int $student_class_id
 * @property int|null $source_template_id
 * @property string $title
 * @property string $status
 * @property \Illuminate\Support\Carbon $plan_start
 * @property \Illuminate\Support\Carbon $plan_end
 * @property int|null $created_by
 */
class StudentLearningPlan extends Model
{
    public const STATUSES = ['draft', 'active', 'archived'];

    protected $table = 'student_learning_plans';

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'campus_id', 'student_id', 'student_class_id', 'source_template_id', 'title', 'status',
        'plan_start', 'plan_end', 'created_by',
    ];

    protected $casts = ['plan_start' => 'date:Y-m-d', 'plan_end' => 'date:Y-m-d'];

    public function units(): HasMany
    {
        return $this->hasMany(StudentPlanUnit::class, 'plan_id')->orderBy('sort_order')->orderBy('id');
    }
}
