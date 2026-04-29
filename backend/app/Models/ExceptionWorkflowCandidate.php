<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExceptionWorkflowCandidate extends Model
{
    protected $table = 'exception_workflow_candidates';

    protected $fillable = [
        'workflow_id',
        'rank',
        'candidate_date',
        'start_time',
        'end_time',
        'teacher_id',
        'room_id',
        'status',
        'score',
        'reasons',
        'expires_at',
    ];

    protected $casts = [
        'candidate_date' => 'date',
        'reasons' => 'array',
        'expires_at' => 'datetime',
    ];

    public function workflow()
    {
        return $this->belongsTo(ExceptionWorkflow::class, 'workflow_id', 'id');
    }
}
