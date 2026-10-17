<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $exam_round_id
 * @property int $exam_round_subject_id
 * @property int $student_id
 * @property float|string|null $score
 * @property bool $is_absent
 * @property string|null $notes
 * @property int|null $recorded_by_user_id
 * @property \Carbon\Carbon|null $recorded_at
 * @method static static create(array $attributes = [])
 */
class ExamScore extends Model
{
    protected $table = 'exam_scores';

    protected $fillable = [
        'exam_round_id', 'exam_round_subject_id', 'student_id',
        'score', 'is_absent', 'notes', 'recorded_by_user_id', 'recorded_at',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'is_absent' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(ExamRoundSubject::class, 'exam_round_subject_id');
    }
}
