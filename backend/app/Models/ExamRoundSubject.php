<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @method static static create(array $attributes = []) */
class ExamRoundSubject extends Model
{
    protected $table = 'exam_round_subjects';

    protected $fillable = ['exam_round_id', 'name', 'sort_order', 'max_score'];

    protected $casts = ['max_score' => 'decimal:2'];

    public function round()
    {
        return $this->belongsTo(ExamRound::class, 'exam_round_id');
    }
}
