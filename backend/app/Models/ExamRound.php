<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * School midterm / periodic exam window (段考場次).
 *
 * @property int $id
 * @property int $campus_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property \Carbon\Carbon|null $exam_date
 * @property \Carbon\Carbon|null $entry_deadline
 * @property array $grade_ids
 * @property int|null $created_by_user_id
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon|null $closed_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ExamRoundSubject> $subjects
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ExamScore> $scores
 * @method static static create(array $attributes = [])
 */
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }
}
