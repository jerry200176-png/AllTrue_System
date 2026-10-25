<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $StudentID
 * @property int|null $StudentClassID
 * @property int|null $InvoiceID
 * @property string|null $reported_by_name
 * @property mixed $payment_date
 * @property string|null $payment_method
 * @property mixed $reported_amount
 * @property string|null $account_last5
 * @property string|null $note
 * @property string|null $status
 * @property int|null $confirmed_by
 * @property mixed $confirmed_at
 * @property string|null $rejection_note
 * @property string|null $report_token_hash
 * @property mixed $token_expires_at
 * @property int|null $payment_id
 * @property int|null $voided_by
 * @property mixed $voided_at
 * @property string|null $void_reason
 * @property string|null $backfill_note
 */
class PaymentReport extends Model
{
    protected $table = 'payment_reports';

    protected $fillable = [
        'StudentID',
        'StudentClassID',
        'InvoiceID',
        'reported_by_name',
        'payment_date',
        'payment_method',
        'reported_amount',
        'account_last5',
        'note',
        'status',
        'confirmed_by',
        'confirmed_at',
        'rejection_note',
        'report_token_hash',
        'token_expires_at',
        'payment_id',
        'voided_by',
        'voided_at',
        'void_reason',
        'backfill_note',
    ];

    protected $casts = [
        'payment_date'    => 'date',
        'confirmed_at'    => 'datetime',
        'token_expires_at' => 'datetime',
        'voided_at'       => 'datetime',
        'reported_amount' => 'decimal:2',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Student, $this> */
    public function student()
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<StudentClass, $this> */
    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Invoice, $this> */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'InvoiceID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function confirmedByUser()
    {
        return $this->belongsTo(User::class, 'confirmed_by', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Payment, $this> */
    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

    /** Receipt note: the linked payment's note wins (confirmation may replace it), else the report's own note. */
    public function displayNote(): string
    {
        $paymentNote = $this->getAttribute('payment_id') ? $this->getRelationValue('payment')?->getAttribute('Note') : null;
        // System markers (confirm() audit fallback, legacy backfill) are metadata, never a display note.
        foreach (['繳費回報核帳確認 (report #', '[系統補建]', '[舊系統補建]'] as $marker) {
            if (is_string($paymentNote) && str_starts_with($paymentNote, $marker)) {
                $paymentNote = null;
            }
        }

        return (string) ($paymentNote ?? $this->note ?? '');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by', 'id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
