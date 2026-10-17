<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @method static static create(array $attributes = []) */
class ExamScoreAuditLog extends Model
{
    protected $table = 'exam_score_audit_logs';

    protected $fillable = [
        'exam_round_id', 'exam_score_id', 'campus_id', 'actor_user_id',
        'action', 'reason', 'before', 'after',
    ];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
