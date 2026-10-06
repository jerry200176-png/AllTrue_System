<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $StudentID
 * @property int|null $StudentClassID
 * @property string $IssueDate
 * @property string|null $DueDate
 * @property int $TotalAmount
 * @property int $PaidAmount
 * @property string $Status
 * @property string|null $ScheduleModeAtIssue
 * @property string $Note
 * @property string|null $billing_period
 * @property array<string, mixed>|null $billing_snapshot
 * @property string|null $reconciled_at
 * @property int|null $reconciled_by
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceItem> $items
 * @property \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property \App\Models\StudentClass|null $studentClass
 */
class Invoice extends Model
{
    protected static function booted(): void
    {
        // A waived (確認不收) contract is terminal: no new billing line may point at it.
        static::creating(function (Invoice $row): void {
            if (StudentClass::isWaivedInDb((int) $row->getAttribute('StudentClassID'))) {
                abort(422, '此合約已確認不收，不能再建立帳單');
            }
        });
    }

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
