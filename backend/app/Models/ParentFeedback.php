<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentFeedback extends Model
{
    protected $table = 'parent_feedback';

    protected $fillable = [
        'student_id',
        'campus_id',
        'category',
        'rating',
        'content',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'rating'  => 'integer',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Student, $this> */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
