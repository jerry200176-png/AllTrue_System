<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->members()->whereNull('unlinked_at');
    }
}
