<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property int|null $subject_id
 * @property int|null $student_class_id
 * @property string $title
 * @property string|null $description
 * @property string $assessment_type
 * @property string $status
 * @property \Carbon\Carbon|null $scheduled_for
 * @property float|string $max_score
 * @property float|string|null $passing_score
 * @property int|null $created_by_user_id
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon|null $closed_at
 * @property \Carbon\Carbon|null $created_at
 * @property-read StudentClass|null $studentClass
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AssessmentResult> $results
 * @method static static create(array $attributes = [])
 */
class Assessment extends Model
{
    protected $table = 'assessments';

    protected $fillable = [
        'campus_id', 'subject_id', 'student_class_id', 'title', 'description',
        'assessment_type', 'status', 'scheduled_for', 'max_score',
        'passing_score', 'created_by_user_id', 'published_at', 'closed_at',
    ];

    protected $casts = [
        'scheduled_for' => 'date:Y-m-d',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
        'max_score' => 'decimal:2',
        'passing_score' => 'decimal:2',
    ];

    public function results()
    {
        return $this->hasMany(AssessmentResult::class, 'assessment_id');
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }
}
