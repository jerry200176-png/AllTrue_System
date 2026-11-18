<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic existing-target allocation audit; Invoice/Payment remain the financial authority.
 * @property int $source_student_class_id
 * @property int $created_by
 */
class ExistingCourseCreditAllocationGroup extends Model
{
    public const SNAPSHOT_PROTOCOL = 'existing-course-credit-allocation:v1';

    protected $table = 'existing_course_credit_allocation_groups';
    protected $fillable = ['operation_key', 'request_fingerprint', 'campus_id', 'student_id',
        'source_student_class_id', 'source_invoice_id', 'created_by', 'sessions', 'amount', 'reason',
        'source_before', 'source_after', 'targets_before', 'targets_after', 'released_sessions',
        'reversed_at', 'reversed_by', 'reverse_reason'];
    protected $casts = ['source_before' => 'array', 'source_after' => 'array', 'targets_before' => 'array',
        'targets_after' => 'array', 'released_sessions' => 'array', 'reversed_at' => 'datetime'];

    public static function storageReady(): bool
    {
        return SubjectCreditTransfer::storageReady()
            && Schema::hasTable('existing_course_credit_allocation_groups')
            && Schema::hasColumns('existing_course_credit_allocation_groups', (new self())->getFillable())
            && Schema::hasColumn('contract_subject_credit_transfers', 'allocation_group_id');
    }

    /** Old code compares the entire snapshot to its v1 capture: an unknown protocol MUST fail closed on rollback. */
    public static function childSnapshot(array $state): array
    {
        return array_merge($state, ['snapshot_protocol' => self::SNAPSHOT_PROTOCOL]);
    }
}
