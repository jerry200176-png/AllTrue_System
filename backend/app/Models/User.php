<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'User';
    public $timestamps = false;

    protected $fillable = [
        'LoginName',
        'Name',
        'PSW',
        'type',
        'employment_type',
        'status',
        'phone',
        'LineID',
        'AvatarUrl',
        'TeachingSessionCount',
        'MustChangePassword',
        'PasswordChangedAt',
        'PasswordSetByUserID',
        'bug_inbox_last_seen_at',
        'bug_inbox_last_seen_bug_id',
        'pin_hash',
        'pin_failed_attempts',
        'pin_locked_until',
        'pin_set_at',
    ];

    protected $hidden = ['PSW', 'pin_hash'];

    protected $casts = [
        'MustChangePassword' => 'boolean',
        'PasswordChangedAt' => 'datetime',
        'bug_inbox_last_seen_at' => 'datetime',
        'pin_locked_until' => 'datetime',
        'pin_set_at' => 'datetime',
    ];
}
