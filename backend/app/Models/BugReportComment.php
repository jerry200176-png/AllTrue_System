<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BugReportComment extends Model
{
    protected $table = 'bug_report_comments';
    public $timestamps = false;

    protected $fillable = [
        'bug_report_id', 'author_user_id', 'body',
        'is_internal_note', 'created_at',
    ];

    protected $casts = [
        'is_internal_note' => 'boolean',
        'created_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<BugReport, $this> */
    public function bugReport()
    {
        return $this->belongsTo(BugReport::class, 'bug_report_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
