<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TrueFit-owned teacher observation with structured JSON payload.
 *
 * @property int $id
 * @property int $campus_id
 * @property int $teacher_user_id
 * @property int $class_session_id
 * @property int $student_class_id
 * @property Carbon|string $session_date
 * @property string $start_time
 * @property string $status
 * @property string $observation_schema_version
 * @property string $confidence
 * @property array<string, mixed> $observation_json
 * @property Carbon|null $observed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TrueFitObservation extends Model
{
    protected $table = 'truefit_observations';

    protected $fillable = [
        'campus_id',
        'teacher_user_id',
        'class_session_id',
        'student_class_id',
        'session_date',
        'start_time',
        'status',
        'observation_schema_version',
        'confidence',
        'observation_json',
        'observed_at',
    ];

    protected $casts = [
        'campus_id' => 'integer',
        'teacher_user_id' => 'integer',
        'class_session_id' => 'integer',
        'student_class_id' => 'integer',
        'observation_json' => 'array',
        'observed_at' => 'datetime',
        'session_date' => 'date',
    ];
}
