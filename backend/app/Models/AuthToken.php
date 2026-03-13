<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthToken extends Model
{
    protected $table = 'auth_tokens';
    public $timestamps = false;

    protected $fillable = ['user_id', 'token', 'expires_at'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
