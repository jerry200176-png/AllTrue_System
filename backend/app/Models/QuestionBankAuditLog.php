<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
