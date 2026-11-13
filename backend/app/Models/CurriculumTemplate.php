<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 教材範本 (#4970 step 2). Campus-scoped; archived instead of deleted.
 *
 * @property int $id
 * @property int $campus_id
 * @property string $subject
 * @property string $grade
 * @property string|null $publisher
 * @property int|null $academic_year
 * @property int|null $semester
 * @property string $title
 * @property string $status
 * @property int|null $created_by
 */
class CurriculumTemplate extends Model
{
    public const STATUSES = ['draft', 'active', 'archived'];

    protected $table = 'curriculum_templates';

    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'campus_id', 'subject', 'grade', 'publisher', 'academic_year', 'semester', 'title', 'status', 'created_by',
    ];

    /** @return HasMany<CurriculumTemplateUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(CurriculumTemplateUnit::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }
}
