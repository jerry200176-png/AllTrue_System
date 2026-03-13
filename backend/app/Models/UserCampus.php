<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCampus extends Model
{
    protected $table = 'UserCampus';
    public $timestamps = false;

    protected $fillable = [
        'CampusID',
        'UserID',
        'Admin',
        'Approved',
    ];
}
