<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentClassPricingAmendment extends Model
{
    protected $table = 'student_class_pricing_amendments';

    public $timestamps = false;

    protected $fillable = [
        'student_class_id',
        'effective_from',
        'rate',
        'rate_unit',
        'source_reference',
        'reason',
        'created_by_user_id',
        'created_at',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected $casts = [
        'rate' => 'integer',
        'created_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }
}
