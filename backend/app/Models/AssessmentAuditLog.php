<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @method static static create(array $attributes = []) */
class AssessmentAuditLog extends Model
{
    protected $table = 'assessment_audit_logs';

    protected $fillable = [
        'assessment_id', 'assessment_result_id', 'campus_id', 'actor_user_id',
        'action', 'reason', 'before', 'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];
}
