<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $actor_name Optional projection/aggregate attribute; no schema change.
 */
class UserLoginActivity extends Model
{
    protected $table = 'user_login_activities';

    protected $fillable = [
        'user_id',
        'login_at',
        'ip_address',
        'user_agent',
        'device_label',
        'success',
        'auth_token_id',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'success' => 'boolean',
    ];
}
