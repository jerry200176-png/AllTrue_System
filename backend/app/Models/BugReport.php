<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Columns from 2026_04_11_000002_create_bug_report_tables.
 *
 * @property int $id
 * @property int $CampusID
 * @property int $reporter_user_id
 * @property string $title
 * @property string $description
 * @property string $severity
 * @property string $status
 * @property string|null $page_key
 * @property string|null $url
 * @property string|null $client_info
 * @property int|null $assigned_to
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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

    public function evidence()
    {
        return $this->hasMany(BugReportEvidence::class, 'bug_report_id');
    }

    public function attachments()
    {
        return $this->hasMany(BugReportAttachment::class, 'bug_report_id');
    }
}
