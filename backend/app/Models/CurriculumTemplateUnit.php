<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One unit of a 教材範本. exam_sequence = which 段考 of the semester it leads to.
 *
 * @property int $id
 * @property int $template_id
 * @property int $sort_order
 * @property string $title
 * @property string|null $note
 * @property int|null $exam_sequence
 */
class CurriculumTemplateUnit extends Model
{
    protected $table = 'curriculum_template_units';

    protected $fillable = ['template_id', 'sort_order', 'title', 'note', 'exam_sequence'];
}
