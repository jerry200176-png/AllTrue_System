<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int    $threadId;
    public int    $userId;
    public string $userName;
    public bool   $isTyping;

    public function __construct(int $threadId, int $userId, string $userName, bool $isTyping)
    {
        $this->threadId = $threadId;
        $this->userId   = $userId;
        $this->userName = $userName;
        $this->isTyping = $isTyping;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.thread.' . $this->threadId);
    }

    public function broadcastAs(): string
    {
        return $this->isTyping ? 'typing.start' : 'typing.stop';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id'   => $this->userId,
            'user_name' => $this->userName,
        ];
    }
}
