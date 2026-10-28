<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property \Carbon\Carbon|null $exam_date
 * @property \Carbon\Carbon|null $entry_deadline
 * @property array|null $grade_ids
 * @property int|null $created_by_user_id
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon|null $closed_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ExamRoundSubject> $subjects
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
}
