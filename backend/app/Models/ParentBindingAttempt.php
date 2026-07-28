<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only PB-00 observation row — not auth/binding truth. */
class ParentBindingAttempt extends Model
{
    public $timestamps = false;
    protected $table = 'parent_binding_attempts';
    protected $fillable = [
        'correlation_id', 'occurred_at', 'channel', 'method', 'outcome', 'reason_code',
        'campus_id', 'student_id', 'phone_fingerprint', 'candidate_count', 'phone_match_count',
    ];
    protected $casts = ['occurred_at' => 'datetime', 'campus_id' => 'integer', 'student_id' => 'integer', 'candidate_count' => 'integer', 'phone_match_count' => 'integer'];
}
