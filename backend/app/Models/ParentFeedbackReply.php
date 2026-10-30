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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ParentFeedback, $this> */
    public function feedback()
    {
        return $this->belongsTo(ParentFeedback::class, 'feedback_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
