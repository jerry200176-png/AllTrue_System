<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatThreadMember extends Model
{
    protected $table = 'chat_thread_members';
    public $timestamps = false;

    protected $fillable = [
        'thread_id', 'user_id', 'role',
        'joined_at', 'left_at', 'is_pinned',
        'last_read_message_id', 'last_read_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_read_at' => 'datetime',
    ];

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
