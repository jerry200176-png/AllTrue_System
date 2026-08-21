<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $question_bank_id
 * @property int|null $question_bank_item_id
 * @property int $campus_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property array|null $before
 * @property array|null $after
 * @method static static create(array $attributes = [])
 */
class QuestionBankAuditLog extends Model
{
    protected $table = 'question_bank_audit_logs';

    protected $fillable = [
        'question_bank_id', 'question_bank_item_id', 'campus_id', 'actor_user_id',
        'action', 'reason', 'before', 'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];
}
