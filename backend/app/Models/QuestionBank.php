<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property int|null $subject_id
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property int|null $created_by_user_id
 * @method static static create(array $attributes = [])
 */
class QuestionBank extends Model
{
    protected $table = 'question_banks';

    protected $fillable = [
        'campus_id', 'subject_id', 'name', 'description', 'status', 'created_by_user_id',
    ];

    public function items()
    {
        return $this->hasMany(QuestionBankItem::class, 'question_bank_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(QuestionBankAuditLog::class, 'question_bank_id');
    }
}
