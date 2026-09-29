<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Student, $this> */
    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\StudentClass, $this> */
    public function studentClass(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Invoice, $this> */
    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'InvoiceID', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function confirmedByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by', 'id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

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
