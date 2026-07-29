<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $group_id
 * @property int $student_class_id
 * @property string $relation_type
 * @property Carbon|null $effective_from
 * @property int $sequence
 * @property string|null $decision_reason
 * @property int|null $created_by
 * @property Carbon|null $unlinked_at
 */
class CourseContractGroupMember extends Model
{
    protected $table = 'course_contract_group_members';

    public const RELATION_TYPES = [
        'original',
        'renewal',
        'replacement',
        'trial',
        'parallel',
    ];

    protected $fillable = [
        'group_id',
        'student_class_id',
        'relation_type',
        'effective_from',
        'sequence',
        'decision_reason',
        'created_by',
        'unlinked_at',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'unlinked_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(CourseContractGroup::class, 'group_id', 'id');
    }

    public function studentClass(): BelongsTo
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }

    public function isActive(): bool
    {
        return $this->unlinked_at === null;
    }
}
