<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @method static static create(array $attributes = []) */
class AssessmentRemediationAction extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'assessment_remediation_actions';

    protected $fillable = [
        'assessment_id', 'assessment_result_id', 'campus_id', 'student_id',
        'student_class_id', 'knowledge_tag', 'action_type', 'status', 'plan',
        'due_date', 'notes', 'created_by_user_id', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function assessment()
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function assessmentResult()
    {
        return $this->belongsTo(AssessmentResult::class, 'assessment_result_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }
}
