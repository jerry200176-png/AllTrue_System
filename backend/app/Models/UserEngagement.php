<?php

namespace App\Models;

use Database\Factories\UserEngagementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserEngagement extends Model
{
    use HasFactory;

    protected static function newFactory(): UserEngagementFactory
    {
        return UserEngagementFactory::new();
    }

    protected $table = 'user_engagement';

    protected $fillable = [
        'user_id',
        'role_track',
        'rank_key',
        'xp_total',
        'rank_display_opt_out',
    ];

    protected $casts = [
        'xp_total' => 'integer',
        'rank_display_opt_out' => 'boolean',
    ];
}
