<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BugReportEvidence extends Model
{
    public const TYPE_RESOLUTION_PRODUCTION_VERIFICATION = 'resolution_production_verification';

    protected $table = 'bug_report_evidence';
    public $timestamps = false;

    protected $fillable = [
        'bug_report_id',
        'evidence_type',
        'production_revision',
        'deploy_run_id',
        'source_ref',
        'verified_by',
        'verified_at',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function bugReport()
    {
        return $this->belongsTo(BugReport::class, 'bug_report_id');
    }

    public function verifiedByUser()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new LogicException('Bug report evidence is append-only and cannot be updated');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Bug report evidence is append-only and cannot be deleted');
    }

    public function forceDelete()
    {
        throw new LogicException('Bug report evidence is append-only and cannot be deleted');
    }
}
