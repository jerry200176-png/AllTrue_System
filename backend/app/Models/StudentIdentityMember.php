<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentIdentityMember extends Model
{
    protected $table = 'student_identity_members';

    protected $fillable = [
        'identity_group_id',
        'student_id',
        'campus_id',
        'status',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'revoked_reason',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(StudentIdentityGroup::class, 'identity_group_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
