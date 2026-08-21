<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $question_bank_id
 * @property string $question_key
 * @property string|null $source_question_key
 * @property int $version_no
 * @property string $question_type
 * @property string $prompt
 * @property array|null $choices
 * @property array|null $answer
 * @property string|null $explanation
 * @property string $knowledge_tag
 * @property int $difficulty
 * @property string $source_type
 * @property string|null $source_name
 * @property string|null $source_version
 * @property string|null $grade_level
 * @property string|null $subject_name
 * @property string|null $source_ref
 * @property string|null $license_ref
 * @property string $status
 * @property int|null $created_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property \Carbon\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property \Carbon\Carbon|null $created_at
 * @property-read QuestionBank|null $bank
 * @method static static create(array $attributes = [])
 */
class QuestionBankItem extends Model
{
    protected $table = 'question_bank_items';

    protected $fillable = [
        'question_bank_id', 'question_key', 'source_question_key', 'version_no', 'question_type', 'prompt',
        'choices', 'answer', 'explanation', 'knowledge_tag', 'difficulty', 'source_type',
        'source_name', 'source_version', 'grade_level', 'subject_name', 'source_ref', 'license_ref',
        'status', 'created_by_user_id', 'reviewed_by_user_id',
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
