<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBadge extends Model
{
    protected $table = 'user_badges';

    protected $fillable = [
        'user_id',
        'badge_key',
        'hidden',
    ];

    protected $casts = [
        'hidden' => 'boolean',
    ];
}
