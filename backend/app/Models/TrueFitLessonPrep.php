<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TrueFit-owned lesson preparation with structured Teacher Brief JSON.
 */
class TrueFitLessonPrep extends Model
{
    protected $table = 'truefit_lesson_preps';

    protected $fillable = [
        'campus_id',
        'teacher_user_id',
        'class_session_id',
        'student_class_id',
        'session_date',
        'start_time',
        'material_unit_key',
        'status',
        'brief_provider',
        'brief_schema_version',
        'brief_json',
        'generated_at',
    ];

    protected $casts = [
        'campus_id' => 'integer',
        'teacher_user_id' => 'integer',
        'class_session_id' => 'integer',
        'student_class_id' => 'integer',
        'brief_schema_version' => 'integer',
        'brief_json' => 'array',
        'generated_at' => 'datetime',
        'session_date' => 'date',
    ];
}
