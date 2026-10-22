<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AuthToken extends Model
{
    protected $table = 'auth_tokens';
    public $timestamps = false;

    protected $fillable = ['user_id', 'token', 'expires_at', 'pin_verified_until'];

    protected $casts = [
        'pin_verified_until' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Sign the user out everywhere: delete every bearer token they hold and return how many were revoked.
     * Same query the self-service password change and account deletion still run inline (not yet moved here).
     * Only this user's rows are touched, so an admin acting on someone else keeps their own session.
     */
    public static function revokeAllForUser(int $userId): int
    {
        if (!Schema::hasTable('auth_tokens')) {
            return 0;
        }

        return (int) static::query()->where('user_id', $userId)->delete();
    }
}
