<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentIdentityGroup extends Model
{
    protected $table = 'student_identity_groups';

    protected $fillable = [
        'display_name',
        'status',
        'created_by_user_id',
    ];

    public function members()
    {
        return $this->hasMany(StudentIdentityMember::class, 'identity_group_id');
    }

    public function access()
    {
        return $this->hasOne(ParentCrossCampusAccess::class, 'identity_group_id');
    }
}
