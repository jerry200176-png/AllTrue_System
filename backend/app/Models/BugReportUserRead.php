<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BugReportUserRead extends Model
{
    protected $table = 'bug_report_user_reads';

    public $timestamps = false;

    protected $fillable = ['user_id', 'bug_report_id', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
