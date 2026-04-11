<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatThread extends Model
{
    protected $table = 'chat_threads';

    protected $fillable = [
        'CampusID', 'type', 'name', 'created_by',
        'last_message_id', 'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function members()
    {
        return $this->hasMany(ChatThreadMember::class, 'thread_id');
    }

    public function activeMembers()
    {
        return $this->hasMany(ChatThreadMember::class, 'thread_id')
            ->whereNull('left_at');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'thread_id');
    }

    public function lastMessage()
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
