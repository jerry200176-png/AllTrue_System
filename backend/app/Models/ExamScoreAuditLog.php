<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $exam_round_id
 * @property int|null $exam_score_id
 * @property int $campus_id
 * @property int $actor_user_id
 * @property string $action
 * @property string|null $reason
 * @property array|null $before
 * @property array|null $after
 * @method static static create(array $attributes = [])
 */
class ExamScoreAuditLog extends Model
{
    protected $table = 'exam_score_audit_logs';

    protected $fillable = [
        'exam_round_id', 'exam_score_id', 'campus_id', 'actor_user_id',
        'action', 'reason', 'before', 'after',
    ];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
