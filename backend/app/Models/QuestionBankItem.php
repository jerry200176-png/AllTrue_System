<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionBankItem extends Model
{
    protected $table = 'question_bank_items';

    protected $fillable = [
        'question_bank_id', 'question_key', 'version_no', 'question_type', 'prompt',
        'choices', 'answer', 'explanation', 'knowledge_tag', 'difficulty', 'source_type',
        'source_ref', 'status', 'created_by_user_id', 'reviewed_by_user_id',
        'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'choices' => 'array',
        'answer' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function bank()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(QuestionBankAuditLog::class, 'question_bank_item_id');
    }
}
