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

    protected static function booted(): void
    {
        // #4100: closing or resolving a report ends triage billing access for good (a reopen needs a fresh link).
        static::saved(function (BugReport $bug): void {
            if ($bug->wasChanged('status') && !in_array((string) $bug->status, BugReportContractLink::OPEN_REPORT_STATUSES, true)) {
                $actor = app()->bound('request') ? request()->attributes->get('auth_user') : null;
                BugReportContractLink::revokeAllForReport((int) $bug->id, $actor instanceof User ? (int) $actor->getKey() : null);
            }
        });
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BugReportComment, $this> */
    public function comments()
    {
        return $this->hasMany(BugReportComment::class, 'bug_report_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BugReportStatusLog, $this> */
    public function statusLogs()
    {
        return $this->hasMany(BugReportStatusLog::class, 'bug_report_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BugReportEvidence, $this> */
    public function evidence()
    {
        return $this->hasMany(BugReportEvidence::class, 'bug_report_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BugReportAttachment, $this> */
    public function attachments()
    {
        return $this->hasMany(BugReportAttachment::class, 'bug_report_id');
    }
}
