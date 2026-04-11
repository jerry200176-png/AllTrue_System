<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BugReport extends Model
{
    protected $table = 'bug_reports';

    protected $fillable = [
        'CampusID', 'reporter_user_id', 'title', 'description',
        'severity', 'status', 'page_key', 'url', 'client_info',
        'assigned_to',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments()
    {
        return $this->hasMany(BugReportComment::class, 'bug_report_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(BugReportStatusLog::class, 'bug_report_id');
    }

    public function attachments()
    {
        return $this->hasMany(BugReportAttachment::class, 'bug_report_id');
    }
}
