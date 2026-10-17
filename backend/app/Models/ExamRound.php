<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @method static static create(array $attributes = []) */
class ExamRound extends Model
{
    protected $table = 'exam_rounds';

    protected $fillable = [
        'campus_id', 'title', 'description', 'status', 'exam_date', 'entry_deadline',
        'grade_ids', 'created_by_user_id', 'published_at', 'closed_at',
    ];

    protected $casts = [
        'exam_date' => 'date:Y-m-d',
        'entry_deadline' => 'date:Y-m-d',
        'grade_ids' => 'array',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function subjects()
    {
        return $this->hasMany(ExamRoundSubject::class, 'exam_round_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scores()
    {
        return $this->hasMany(ExamScore::class, 'exam_round_id');
    }
}
