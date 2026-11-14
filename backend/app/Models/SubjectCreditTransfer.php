<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Durable #535 operation trace; Invoice/Payment remain the money authority.
 * @property int $student_id
 * @property int $source_student_class_id
 * @property int $template_student_class_id
 * @property int $target_student_class_id
 * @property int $source_invoice_id
 * @property int $target_invoice_id
 * @property int $transfer_out_payment_id
 * @property int $transfer_in_payment_id
 * @property int $created_by
 */
class SubjectCreditTransfer extends Model
{
    protected $table = 'contract_subject_credit_transfers';
    protected $fillable = [
        'operation_key', 'request_fingerprint', 'campus_id', 'student_id', 'source_student_class_id',
        'template_student_class_id', 'target_student_class_id', 'source_invoice_id', 'target_invoice_id',
        'transfer_out_payment_id', 'transfer_in_payment_id', 'sessions', 'amount', 'unit_price', 'lesson_minutes',
        'reason', 'created_by', 'source_before', 'source_after', 'target_after', 'reversed_at', 'reversed_by',
        'reverse_reason', 'reverse_out_payment_id', 'reverse_in_payment_id',
    ];
    protected $casts = [
        'source_before' => 'array', 'source_after' => 'array', 'target_after' => 'array', 'reversed_at' => 'datetime',
    ];

    public static function storageReady(): bool
    {
        return Schema::hasTable('contract_subject_credit_transfers')
            && Schema::hasColumns('contract_subject_credit_transfers', (new self())->getFillable())
            && Schema::hasColumns('StudentClass', ['SessionCount', 'SessionDuration', 'Rate', 'Charge', 'UsedSessions',
                'RemainingSessions', 'scheduling_policy', 'deduction_basis', 'standard_lesson_minutes', 'closed_reason']);
    }
}
