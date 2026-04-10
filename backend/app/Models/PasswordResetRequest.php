<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetRequest extends Model
{
    protected $table = 'password_reset_requests';

    protected $fillable = [
        'user_id',
        'account_input',
        'role_input',
        'status',
        'requested_ip',
        'request_note',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];
}
