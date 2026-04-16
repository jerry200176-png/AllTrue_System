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
        'status',
        'confirmed_by',
        'confirmed_at',
        'rejection_note',
        'report_token_hash',
        'token_expires_at',
        'payment_id',
    ];

    protected $casts = [
        'payment_date'    => 'date',
        'confirmed_at'    => 'datetime',
        'token_expires_at' => 'datetime',
        'reported_amount' => 'decimal:2',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'InvoiceID', 'id');
    }

    public function confirmedByUser()
    {
        return $this->belongsTo(User::class, 'confirmed_by', 'id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
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
