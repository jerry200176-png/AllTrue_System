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
        'AvatarUrl',
        'TeachingSessionCount',
        'MustChangePassword',
        'PasswordChangedAt',
        'PasswordSetByUserID',
    ];

    protected $hidden = ['PSW'];

    protected $casts = [
        'MustChangePassword' => 'boolean',
        'PasswordChangedAt' => 'datetime',
    ];
}
