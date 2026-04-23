<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSignIn extends Model
{
    protected $table = 'TeacherSingIn';
    public $timestamps = false;

    protected $fillable = [
        'TeacherID',
        'CampusID',
        'SignInDT',
        'SignOutDT',
        'MDT',
        'Source',
        'Status',
    ];

    public function adjustment(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TeacherSignInAdjustment::class, 'teacher_signin_id');
    }
}
