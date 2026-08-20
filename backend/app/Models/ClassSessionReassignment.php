<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log row for POST /api/v1/class-sessions/{id}/reassign-contract.
 *
 * @property int $id
 * @property int $class_session_id
 * @property int $old_student_class_id
 * @property int $new_student_class_id
 * @property string $reason
 * @property int|null $performed_by
 */
class ClassSessionReassignment extends Model
{
    protected $table = 'class_session_reassignments';

    public $timestamps = false;

    protected $fillable = [
        'class_session_id',
        'old_student_class_id',
        'new_student_class_id',
        'reason',
        'performed_by',
        'created_at',
    ];
}
