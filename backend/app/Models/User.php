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
    ];

    protected $hidden = ['PSW'];

    protected $casts = [
        'MustChangePassword' => 'boolean',
        'PasswordChangedAt' => 'datetime',
        'bug_inbox_last_seen_at' => 'datetime',
    ];
}
