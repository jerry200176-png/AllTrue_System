<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRecordFeedbackReply extends Model
{
    protected $table = 'learning_record_feedback_replies';

    protected $fillable = [
        'feedback_id',
        'author_user_id',
        'author_role',
        'parent_session_id',
        'content',
    ];

    public function feedback()
    {
        return $this->belongsTo(LearningRecordFeedback::class, 'feedback_id', 'id');
    }
}
