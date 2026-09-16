<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TrueFit-owned lesson preparation with structured Teacher Brief JSON.
 *
 * @property int $id
 * @property int $campus_id
 * @property int $teacher_user_id
 * @property int $class_session_id
 * @property int $student_class_id
 * @property Carbon|string $session_date
 * @property string $start_time
 * @property string $material_unit_key
 * @property string $status
 * @property string $brief_provider
 * @property int $brief_schema_version
 * @property array<string, mixed> $brief_json
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
