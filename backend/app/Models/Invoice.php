<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'Invoice';

    protected $fillable = [
        'StudentID',
        'StudentClassID',
        'IssueDate',
        'DueDate',
        'TotalAmount',
        'PaidAmount',
        'Status',
        'ScheduleModeAtIssue',
        'Note',
        'reconciled_at',
        'reconciled_by',
        'billing_period',
    ];

    public function scopeNotVoided($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('Status')->orWhere('Status', '!=', 'void');
        });
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'InvoiceID', 'id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'InvoiceID', 'id');
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    /**
     * #934: true only when we know the mode at issue AND it has since changed.
     * NULL ScheduleModeAtIssue (every pre-existing row) always returns false —
     * "unknown" is not "stale", so old invoices never get a false-positive badge.
     */
    public function billingModeChangedSinceIssue(): bool
    {
        if (!$this->ScheduleModeAtIssue) {
            return false;
        }
        $current = $this->studentClass?->ScheduleMode;
        return $current !== null && $current !== $this->ScheduleModeAtIssue;
    }
}
