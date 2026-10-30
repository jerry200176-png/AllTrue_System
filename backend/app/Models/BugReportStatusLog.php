<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Illuminate\Support\Carbon|null $created_at May be absent on partial/unsaved models; required on persisted rows.
 */
class BugReportStatusLog extends Model
{
    protected $table = 'bug_report_status_logs';
    public $timestamps = false;

    protected $fillable = [
        'bug_report_id', 'changed_by',
        'from_status', 'to_status', 'note', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<BugReport, $this> */
    public function bugReport()
    {
        return $this->belongsTo(BugReport::class, 'bug_report_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
