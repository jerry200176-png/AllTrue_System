<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    protected $table = 'ApiClient';

    protected $fillable = [
        'Name',
        'ApiKeyHash',
        'CampusID',
        'Active',
    ];
}
