<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';
    public $timestamps = false;

    protected $fillable = [
        'thread_id', 'sender_user_id', 'sender_name_snapshot',
        'body', 'message_type', 'created_at',
        'deleted_at', 'media_url', 'media_type', 'media_name', 'reply_to_message_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_message_id');
    }
}
