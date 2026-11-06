<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-school 段考 date range. grade_ids null = all grades.
 *
 * @property int $id
 * @property int $campus_id
 * @property string $school_name
 * @property int $academic_year
 * @property int $semester
 * @property int $seq
 * @property string $label
 * @property array|null $grade_ids
 * @property string $grade_key
 */
class SchoolExamPeriod extends Model
{
    protected $table = 'school_exam_periods';

    protected $fillable = [
        'campus_id', 'school_name', 'academic_year', 'semester', 'seq', 'label',
        'start_date', 'end_date', 'grade_ids', 'grade_key', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'grade_ids' => 'array',
    ];
}
