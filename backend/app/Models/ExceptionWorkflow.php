<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExceptionWorkflow extends Model
{
    protected $table = 'exception_workflows';

    protected $fillable = [
        'source_key',
        'campus_id',
        'student_id',
        'student_class_id',
        'class_session_id',
        'type',
        'status',
        'severity',
        'source_type',
        'source_id',
        'owner_user_id',
        'due_at',
        'closed_at',
        'closed_reason',
        'payload',
        'created_by_user_id',
        'parent_session_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'due_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function candidates()
    {
        return $this->hasMany(ExceptionWorkflowCandidate::class, 'workflow_id', 'id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id', 'id');
    }
}
