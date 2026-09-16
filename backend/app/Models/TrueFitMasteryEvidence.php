<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $campus_id
 * @property int $teacher_user_id
 * @property int $class_session_id
 * @property int $student_class_id
 * @property Carbon|string $session_date
 * @property string $start_time
 * @property int|null $source_remediation_id
 * @property string $status
 * @property string $mastery_schema_version
 * @property string $outcome
 * @property string $next_review_window
 * @property string $teacher_decision
 * @property array<string, mixed> $mastery_json
 * @property Carbon|null $checked_at
 */
class TrueFitMasteryEvidence extends Model
{
    protected $table = 'truefit_mastery_evidence';

    protected $fillable = [
        'campus_id', 'teacher_user_id', 'class_session_id', 'student_class_id', 'session_date', 'start_time',
        'source_remediation_id', 'status', 'mastery_schema_version', 'outcome', 'next_review_window',
        'teacher_decision', 'mastery_json', 'checked_at',
    ];

    protected $casts = [
        'campus_id' => 'integer',
        'teacher_user_id' => 'integer',
        'class_session_id' => 'integer',
        'student_class_id' => 'integer',
        'source_remediation_id' => 'integer',
        'mastery_json' => 'array',
        'checked_at' => 'datetime',
        'session_date' => 'date',
    ];
}
