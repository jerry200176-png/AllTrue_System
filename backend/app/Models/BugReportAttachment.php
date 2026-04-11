<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BugReportAttachment extends Model
{
    protected $table = 'bug_report_attachments';

    public $timestamps = false;

    protected $fillable = [
        'bug_report_id', 'stored_path', 'original_name', 'mime_type', 'size',
    ];

    public function bugReport()
    {
        return $this->belongsTo(BugReport::class, 'bug_report_id');
    }
}
