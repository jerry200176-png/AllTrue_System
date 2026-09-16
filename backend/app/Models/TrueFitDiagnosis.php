<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TrueFit-owned misconception diagnosis with structured JSON payload.
 *
 * @property int $id
 * @property int $campus_id
 * @property int $teacher_user_id
 * @property int $class_session_id
 * @property int $student_class_id
 * @property Carbon|string $session_date
 * @property string $start_time
 * @property int|null $source_observation_id
 * @property string $status
 * @property string $diagnosis_schema_version
 * @property string $confidence
 * @property string $teacher_decision
 * @property array<string, mixed> $diagnosis_json
 * @property Carbon|null $proposed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TrueFitDiagnosis extends Model
{
    protected $table = 'truefit_diagnoses';

    protected $fillable = [
        'campus_id',
        'teacher_user_id',
        'class_session_id',
        'student_class_id',
        'session_date',
        'start_time',
        'source_observation_id',
        'status',
        'diagnosis_schema_version',
        'confidence',
        'teacher_decision',
        'diagnosis_json',
        'proposed_at',
    ];

    protected $casts = [
        'campus_id' => 'integer',
        'teacher_user_id' => 'integer',
        'class_session_id' => 'integer',
        'student_class_id' => 'integer',
        'source_observation_id' => 'integer',
        'diagnosis_json' => 'array',
        'proposed_at' => 'datetime',
        'session_date' => 'date',
    ];
}
