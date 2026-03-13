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
    ];
}
