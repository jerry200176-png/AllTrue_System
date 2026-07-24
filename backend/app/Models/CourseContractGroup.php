<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $student_id
 * @property int $campus_id
 * @property int|null $subject_id
 * @property string|null $label
 * @property int|null $created_by
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CourseContractGroupMember> $activeMembers
 */
class CourseContractGroup extends Model
{
    protected $table = 'course_contract_groups';

    protected $fillable = [
        'student_id',
        'campus_id',
        'subject_id',
        'label',
        'created_by',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(CourseContractGroupMember::class, 'group_id', 'id');
    }

    public function activeMembers(): HasMany
    {
        /** @var HasMany $rel */
        $rel = $this->members();

        return $rel->whereNull('unlinked_at');
    }
}
