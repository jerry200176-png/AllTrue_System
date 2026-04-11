<?php

use App\Models\ChatThreadMember;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
| Private channel authorization for chat threads.
| A user can listen on a thread channel only if they are an active member.
*/

Broadcast::channel('chat.thread.{threadId}', function ($user, $threadId) {
    $isMember = ChatThreadMember::where('thread_id', $threadId)
        ->where('user_id', $user->id)
        ->whereNull('left_at')
        ->exists();

    return $isMember;
});
