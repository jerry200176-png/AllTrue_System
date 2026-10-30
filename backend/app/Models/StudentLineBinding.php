<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int|string|null $cnt Optional projection/aggregate attribute; no schema change.
 * @property int|string|null $binding_count Optional projection/aggregate attribute; no schema change.
 */
class StudentLineBinding extends Model
{
    protected $table = 'student_line_bindings';

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'line_user_id',
        'campus_id',
        'bound_at',
        'verified_at',
        'verification_method',
        'notify_learning_feedback',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'verified_at' => 'datetime',
        'notify_learning_feedback' => 'boolean',
    ];

    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Student, $this> */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
