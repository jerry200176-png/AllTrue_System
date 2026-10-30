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
 * @property string|null $zero_amount_reason
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
    /** #3991 (Founder 2026-10-13): a NT$0 bill exists only when marked 免費／贈送; a missing or 0 fee is never free. */
    public const ZERO_REASON_FREE_GIFT = 'free_gift';

    public const ZERO_REASON_FREE_GIFT_LABEL = '免費／贈送';

    /** #4018 (Founder 2026-10-29): a renewal discounted to NT$0 is settled; the renewal sets this with the approver in reconciled_by and the discount snapshot as evidence. */
    public const ZERO_REASON_FULL_DISCOUNT = 'full_discount';

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
        'zero_amount_reason',
    ];

    protected $casts = [
        'billing_snapshot' => 'array',
    ];

    public function isMarkedFreeGift(): bool
    {
        return (string) $this->getAttribute('zero_amount_reason') === self::ZERO_REASON_FREE_GIFT;
    }

    public function isFullDiscount(): bool
    {
        return (string) $this->getAttribute('zero_amount_reason') === self::ZERO_REASON_FULL_DISCOUNT;
    }

    /**
     * #3991 review: the mark settles a term only while its course still has no fee on record (a fee added by any path voids its effect).
     * #4017: a caller checking many terms of one course passes courseHasFee() read once.
     */
    public function freeGiftHolds(?StudentClass $course, ?bool $courseHasFee = null): bool
    {
        // #4018: a full-discount term settles while its bill stays NT$0 (the course has a fee by design); a fee added later bills a positive amount.
        if ($this->isFullDiscount()) {
            // Fail closed: settled only while this bill is NT$0, the snapshot says final 0, the course still charges 0 and no other live positive bill exists for its period.
            return $course !== null && (int) $this->getAttribute('TotalAmount') === 0
                && (int) ($this->billing_snapshot['final_amount'] ?? -1) === 0
                && (int) ($course->Charge ?? 0) === 0
                && !self::courseHasPositiveBill($course, $this->billing_period);
        }

        return $this->isMarkedFreeGift() && $course !== null && !($courseHasFee ?? self::courseHasFee($course));
    }

    /** #3991: a fee or money on record (rate, charge, package, legacy Paid/Pay/PayDate, live priced amendment, positive live bill line) rules out 免費／贈送. */
    public static function courseHasFee(StudentClass $course): bool
    {
        return (int) ($course->Paid ?? 0) === 1 || (int) ($course->Pay ?? 0) > 0 || !empty($course->PayDate) || (float) ($course->Rate ?? 0) > 0 || (int) ($course->Charge ?? 0) > 0 || (int) ($course->PackageID ?? 0) > 0
            || StudentClassPricingAmendment::query()->where('student_class_id', $course->ID)->whereNull('voided_at')->where('rate', '>', 0)->exists()
            || self::courseHasPositiveBill($course);
    }

    /** A live positive bill, or a positive line, naming the course; $period limits it to that billing period (or an undated bill); $openOnly skips a bill its payments already cover. */
    public static function courseHasPositiveBill(StudentClass $course, ?string $period = null, bool $openOnly = false): bool
    {
        return self::query()->scopes('notVoided')->where(fn ($q) => $q->where(fn ($a) => $a->where('StudentClassID', $course->ID)->where('TotalAmount', '>', 0)) // a positive bill on the course itself
            ->orWhereIn('id', InvoiceItem::query()->where('StudentClassID', $course->ID)->where('Amount', '>', 0)->select('InvoiceID')))
            ->when($period !== null, fn ($q) => $q->where(fn ($p) => $p->whereNull('billing_period')->orWhere('billing_period', $period)))
            ->when($openOnly, fn ($q) => $q->whereRaw('Invoice.TotalAmount > ' . self::netPaidSql()))->exists();
    }

    /** #4005: priced evidence beyond the course's own columns: a live priced amendment, or a positive live bill (line) naming the course ($openOnly: still owing). */
    public static function courseHasBillEvidence(StudentClass $course, bool $openOnly = false): bool
    {
        return StudentClassPricingAmendment::query()->where('student_class_id', $course->ID)->whereNull('voided_at')->where('rate', '>', 0)->exists()
            || self::courseHasPositiveBill($course, null, $openOnly);
    }

    public function scopeNotVoided($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('Status')->orWhere('Status', '!=', 'void');
        });
    }

    /**
     * SQL: this invoice's net applied Payment rows (positive non-void minus every reversal), the same rule as
     * InvoiceAmountReconciliationService. For SQL pre-filters only; the resolver still decides the real amount.
     */
    public static function netPaidSql(string $invoice = 'Invoice'): string
    {
        return "GREATEST(0, (SELECT COALESCE(SUM(CASE WHEN P.Amount > 0 AND COALESCE(P.Method, '') <> 'void' THEN P.Amount"
            . " WHEN P.Amount < 0 OR P.Method = 'void' THEN -ABS(P.Amount) ELSE 0 END), 0) FROM Payment P WHERE P.InvoiceID = $invoice.id))";
    }

    /**
     * SQL: a non-void invoice that may still owe. Payment rows are the truth (Founder Q2, R28): a stored Status of
     * "paid" whose Payment rows fall short still owes (#3781). Whether a PAID shared package's member stale-paid bill
     * reopens the package is undecided (#3785 hold), so those keep the stored Status; an unpaid package holds nothing.
     */
    public static function mayOweSql(string $invoice = 'Invoice'): string
    {
        return "($invoice.Status IS NULL OR $invoice.Status NOT IN ('paid', 'void')"
            . " OR ($invoice.Status = 'paid' AND $invoice.TotalAmount > " . self::netPaidSql($invoice)
            . " AND NOT EXISTS (SELECT 1 FROM StudentClass PS JOIN course_packages CP ON CP.id = PS.PackageID"
            . " WHERE PS.ID = $invoice.StudentClassID AND CP.paid = 1)))";
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
