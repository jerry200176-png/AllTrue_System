<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentSession extends Model
{
    protected $table = 'ParentSession';

    protected $fillable = [
        'StudentID',
        'identity_group_id',
        'line_user_id',
        'TokenHash',
        'ExpiresAt',
    ];

    protected $casts = [
        'ExpiresAt' => 'datetime',
    ];
}
