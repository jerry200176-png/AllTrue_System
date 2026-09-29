<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentFeedbackReply extends Model
{
    protected $table = 'parent_feedback_replies';

    protected $fillable = [
        'feedback_id',
        'user_id',
        'replier_role',
        'content',
    ];

    public function feedback()
    {
        return $this->belongsTo(ParentFeedback::class, 'feedback_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this> */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
