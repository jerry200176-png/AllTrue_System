<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $assessment_id
 * @property int $student_id
 * @property int|null $student_class_id
 * @property int $attempt_no
 * @property float|string $score
 * @property float|string $max_score_snapshot
 * @property float|string $percent
 * @property string $status
 * @property string|null $notes
 * @property int|null $recorded_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property \Carbon\Carbon|null $recorded_at
 * @property \Carbon\Carbon|null $reviewed_at
 * @property-read Assessment|null $assessment
 * @property-read Student|null $student
 * @property-read StudentClass|null $studentClass
 * @method static static create(array $attributes = [])
 */
class AssessmentResult extends Model
{
    protected $table = 'assessment_results';

    protected $fillable = [
        'assessment_id', 'student_id', 'student_class_id', 'attempt_no',
        'score', 'max_score_snapshot', 'percent', 'status', 'notes',
        'recorded_by_user_id', 'reviewed_by_user_id', 'recorded_at',
        'reviewed_at',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score_snapshot' => 'decimal:2',
        'percent' => 'decimal:2',
        'recorded_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function assessment()
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }

    public function remediationActions()
    {
        return $this->hasMany(AssessmentRemediationAction::class, 'assessment_result_id');
    }
}
