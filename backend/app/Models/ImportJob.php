<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $table = 'ImportJob';

    protected $fillable = [
        'Type',
        'Status',
        'FileName',
        'Summary',
        'ErrorLog',
    ];
}
