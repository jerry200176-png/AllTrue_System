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
        'Note',
        'reconciled_at',
        'reconciled_by',
    ];

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
}
