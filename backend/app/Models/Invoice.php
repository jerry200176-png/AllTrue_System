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
        'billing_snapshot',
    ];

    protected $casts = [
        'billing_snapshot' => 'array',
    ];

    public function scopeNotVoided($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('Status')->orWhere('Status', '!=', 'void');
        });
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Student, $this> */
    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\InvoiceItem, $this> */
    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'InvoiceID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Payment, $this> */
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class, 'InvoiceID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\StudentClass, $this> */
    public function studentClass(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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
