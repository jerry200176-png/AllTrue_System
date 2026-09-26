<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCapabilityGrant extends Model
{
    protected $table = 'user_capability_grants';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'capability',
        'campus_id',
        'granted_by',
        'granted_at',
        'revoked_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
