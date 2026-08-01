<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentIdentityAuditLog extends Model
{
    protected $table = 'student_identity_audit_logs';

    protected $fillable = [
        'identity_group_id',
        'student_id',
        'campus_id',
        'actor_user_id',
        'action',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
