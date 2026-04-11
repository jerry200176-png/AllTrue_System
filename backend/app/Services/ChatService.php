<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ChatThreadMember;
use App\Models\User;
use App\Support\PublicAvatarUrl;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatService
{
    // ── Avatar URL helpers ─────────────────────────────────────────

    public static function publicAvatarUrl(?string $avatar): ?string
    {
        return PublicAvatarUrl::forBrowser($avatar);
    }

    /**
     * Convert a storage-relative media path to a root-relative browser URL.
     * e.g. "chat-attachments/3/abc.jpg" → "/storage/chat-attachments/3/abc.jpg"
     */
    public static function mediaUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, '/')) return $path;
        return '/storage/' . $path;
    }

    // ── Serialisation ──────────────────────────────────────────────

    /**
     * @param  string|null  $senderAvatarUrl  Pre-resolved URL to avoid extra query.
     * @param  array|null   $replyToData      Pre-fetched reply_to payload (avoids N+1).
     */
    public static function messageToArray(
        ChatMessage $m,
        ?string $senderAvatarUrl = null,
        ?array $replyToData = null
    ): array {
        if ($senderAvatarUrl === null) {
            $sender = User::find($m->sender_user_id);
            $senderAvatarUrl = self::publicAvatarUrl($sender->AvatarUrl ?? null);
        }

        $isDeleted = $m->deleted_at !== null;

        return [
            'id'                   => $m->id,
            'thread_id'            => $m->thread_id,
            'sender_user_id'       => $m->sender_user_id,
            'sender_name'          => $m->sender_name_snapshot,
            'sender_avatar_url'    => $senderAvatarUrl,
            'body'                 => $isDeleted ? null : $m->body,
            'message_type'         => $m->message_type,
            'media_url'            => $isDeleted ? null : self::mediaUrl($m->media_url),
            'media_type'           => $isDeleted ? null : $m->media_type,
            'media_name'           => $isDeleted ? null : $m->media_name,
            'reply_to_message_id'  => $m->reply_to_message_id,
            'reply_to'             => $replyToData,
            'is_deleted'           => $isDeleted,
            'deleted_at'           => $m->deleted_at?->toIso8601String(),
            'created_at'           => $m->created_at?->toIso8601String(),
        ];
    }

    // ── Thread creation ────────────────────────────────────────────

    public static function findOrCreateDm(int $campusId, int $userId, int $otherUserId): ChatThread
    {
        $existing = ChatThread::where('type', 'dm')
            ->where('CampusID', $campusId)
            ->whereHas('activeMembers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->whereHas('activeMembers', function ($q) use ($otherUserId) {
                $q->where('user_id', $otherUserId);
            })
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($campusId, $userId, $otherUserId) {
            $thread = ChatThread::create([
                'CampusID'   => $campusId,
                'type'       => 'dm',
                'created_by' => $userId,
            ]);

            $now = Carbon::now();
            ChatThreadMember::insert([
                ['thread_id' => $thread->id, 'user_id' => $userId,      'role' => 'owner',  'joined_at' => $now],
                ['thread_id' => $thread->id, 'user_id' => $otherUserId, 'role' => 'member', 'joined_at' => $now],
            ]);

            return $thread;
        });
    }

    public static function createGroup(int $campusId, int $creatorId, string $name, array $memberUserIds): ChatThread
    {
        return DB::transaction(function () use ($campusId, $creatorId, $name, $memberUserIds) {
            $thread = ChatThread::create([
                'CampusID'   => $campusId,
                'type'       => 'group',
                'name'       => $name,
                'created_by' => $creatorId,
            ]);

            $now  = Carbon::now();
            $rows = [
                ['thread_id' => $thread->id, 'user_id' => $creatorId, 'role' => 'owner', 'joined_at' => $now],
            ];
            foreach ($memberUserIds as $uid) {
                if ((int) $uid !== $creatorId) {
                    $rows[] = ['thread_id' => $thread->id, 'user_id' => (int) $uid, 'role' => 'member', 'joined_at' => $now];
                }
            }
            ChatThreadMember::insert($rows);

            return $thread;
        });
    }

    // ── Thread list ────────────────────────────────────────────────

    public static function listThreads(int $userId, array $campusIds, int $perPage = 30): array
    {
        $query = ChatThread::query()
            ->join('chat_thread_members as ctm', function ($join) use ($userId) {
                $join->on('chat_threads.id', '=', 'ctm.thread_id')
                    ->where('ctm.user_id', '=', $userId)
                    ->whereNull('ctm.left_at');
            })
            ->select([
                'chat_threads.*',
                'ctm.last_read_message_id',
                'ctm.role as member_role',
                'ctm.is_pinned',
            ]);

        if (!empty($campusIds)) {
            $query->whereIn('chat_threads.CampusID', $campusIds);
        }

        // Pinned threads first, then by last activity
        $query->orderByRaw('ctm.is_pinned DESC, COALESCE(chat_threads.last_message_at, chat_threads.created_at) DESC');

        $threads = $query->paginate($perPage);

        $result = [];
        foreach ($threads as $thread) {
            $lastMsg = $thread->last_message_id
                ? ChatMessage::find($thread->last_message_id)
                : null;

            $unreadCount = 0;
            if ($thread->last_message_id) {
                $lastRead    = $thread->last_read_message_id ?? 0;
                $unreadCount = ChatMessage::where('thread_id', $thread->id)
                    ->where('id', '>', $lastRead)
                    ->count();
            }

            $otherMembers = [];
            if ($thread->type === 'dm') {
                $otherMembers = ChatThreadMember::where('thread_id', $thread->id)
                    ->where('user_id', '!=', $userId)
                    ->whereNull('left_at')
                    ->with('user:id,Name,AvatarUrl')
                    ->get()
                    ->map(fn ($m) => [
                        'id'         => $m->user_id,
                        'name'       => $m->user->Name ?? '',
                        'avatar_url' => self::publicAvatarUrl($m->user->AvatarUrl ?? null),
                    ])
                    ->values()
                    ->all();
            }

            $threadAvatarUrl = $thread->type === 'dm' ? ($otherMembers[0]['avatar_url'] ?? null) : null;

            // Last message preview
            $lastMsgPreview = null;
            if ($lastMsg) {
                if ($lastMsg->deleted_at) {
                    $body = '此訊息已刪除';
                } elseif ($lastMsg->message_type === 'image') {
                    $body = '[圖片]';
                } elseif ($lastMsg->message_type === 'file') {
                    $body = '[檔案] ' . ($lastMsg->media_name ?? '');
                } else {
                    $body = mb_substr($lastMsg->body ?? '', 0, 80);
                }

                $lastMsgPreview = [
                    'id'          => $lastMsg->id,
                    'body'        => $body,
                    'sender_name' => $lastMsg->sender_name_snapshot,
                    'created_at'  => $lastMsg->created_at?->toIso8601String(),
                ];
            }

            $result[] = [
                'id'               => $thread->id,
                'type'             => $thread->type,
                'name'             => $thread->type === 'group' ? $thread->name : ($otherMembers[0]['name'] ?? ''),
                'thread_avatar_url' => $threadAvatarUrl,
                'is_pinned'        => (bool) $thread->is_pinned,
                'campus_id'        => $thread->CampusID,
                'unread_count'     => $unreadCount,
                'member_role'      => $thread->member_role,
                'last_message'     => $lastMsgPreview,
                'other_members'    => $otherMembers,
                'updated_at'       => ($thread->last_message_at ?? $thread->created_at)?->toIso8601String(),
            ];
        }

        return [
            'data'         => $result,
            'current_page' => $threads->currentPage(),
            'last_page'    => $threads->lastPage(),
            'total'        => $threads->total(),
        ];
    }

    // ── Messages ───────────────────────────────────────────────────

    /**
     * Get paginated messages (cursor-based, newest first), with reply_to batch-loaded.
     */
    public static function getMessages(int $threadId, ?int $beforeId = null, int $limit = 40): array
    {
        $query = ChatMessage::where('thread_id', $threadId)
            ->orderByDesc('id');

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $messages = $query->limit($limit)->get();

        // Batch-load sender avatars
        $senderIds       = $messages->pluck('sender_user_id')->unique()->filter()->values()->all();
        $avatarByUserId  = [];
        if (!empty($senderIds)) {
            foreach (User::whereIn('id', $senderIds)->get(['id', 'AvatarUrl']) as $u) {
                $avatarByUserId[$u->id] = self::publicAvatarUrl($u->AvatarUrl ?? null);
            }
        }

        // Batch-load reply_to messages (avoid N+1)
        $replyIds   = $messages->pluck('reply_to_message_id')->filter()->unique()->values()->all();
        $replyMap   = [];
        if (!empty($replyIds)) {
            foreach (ChatMessage::whereIn('id', $replyIds)->get() as $rm) {
                $replyMap[$rm->id] = [
                    'id'          => $rm->id,
                    'sender_name' => $rm->sender_name_snapshot,
                    'body'        => $rm->deleted_at ? null : mb_substr($rm->body ?? '', 0, 100),
                    'is_deleted'  => $rm->deleted_at !== null,
                    'media_type'  => $rm->media_type,
                    'message_type' => $rm->message_type,
                ];
            }
        }

        return $messages
            ->map(fn ($m) => self::messageToArray(
                $m,
                $avatarByUserId[$m->sender_user_id] ?? null,
                isset($m->reply_to_message_id) ? ($replyMap[$m->reply_to_message_id] ?? null) : null
            ))
            ->values()
            ->all();
    }

    /**
     * Send a text message (optionally with a reply reference).
     */
    public static function sendMessage(
        int $threadId,
        int $senderUserId,
        string $body,
        ?int $replyToMessageId = null
    ): ChatMessage {
        $sender     = User::find($senderUserId);
        $senderName = $sender->Name ?? 'Unknown';

        return DB::transaction(function () use ($threadId, $senderUserId, $senderName, $body, $replyToMessageId) {
            $msg = ChatMessage::create([
                'thread_id'            => $threadId,
                'sender_user_id'       => $senderUserId,
                'sender_name_snapshot' => $senderName,
                'body'                 => $body,
                'message_type'         => 'text',
                'reply_to_message_id'  => $replyToMessageId,
                'created_at'           => Carbon::now(),
            ]);

            ChatThread::where('id', $threadId)->update([
                'last_message_id' => $msg->id,
                'last_message_at' => $msg->created_at,
            ]);

            ChatThreadMember::where('thread_id', $threadId)
                ->where('user_id', $senderUserId)
                ->update([
                    'last_read_message_id' => $msg->id,
                    'last_read_at'         => $msg->created_at,
                ]);

            return $msg;
        });
    }

    // ── Delete message (soft) ──────────────────────────────────────

    /**
     * Soft-delete a message. Only the sender can delete their own message.
     * Returns the updated message.
     */
    public static function softDeleteMessage(int $messageId, int $userId): ChatMessage
    {
        $msg = ChatMessage::find($messageId);
        if (!$msg) {
            abort(404, 'Message not found');
        }
        if ($msg->sender_user_id !== $userId) {
            abort(403, 'Forbidden');
        }
        if ($msg->deleted_at !== null) {
            return $msg; // already deleted
        }

        $msg->deleted_at = Carbon::now();
        $msg->save();

        return $msg;
    }

    // ── Delete thread (hard) ───────────────────────────────────────

    /**
     * Permanently delete a thread and all its messages/members.
     * DM: either member may delete.
     * Group: requires owner role OR director auth_role.
     */
    public static function deleteThread(int $threadId, int $userId, string $authRole): void
    {
        $thread = ChatThread::find($threadId);
        if (!$thread) {
            abort(404, 'Thread not found');
        }

        if ($thread->type === 'group') {
            $member = ChatThreadMember::where('thread_id', $threadId)
                ->where('user_id', $userId)
                ->whereNull('left_at')
                ->first();

            if (!$member) {
                abort(403, 'Forbidden');
            }
            if ($member->role !== 'owner' && $authRole !== 'director' && $authRole !== 'super_admin') {
                abort(403, 'Only the group owner or a director can delete this group');
            }
        }
        // DM: membership already checked in controller via isMember()

        DB::transaction(function () use ($threadId) {
            ChatMessage::where('thread_id', $threadId)->delete();
            ChatThreadMember::where('thread_id', $threadId)->delete();
            ChatThread::where('id', $threadId)->delete();
        });
    }

    // ── Attachment upload ──────────────────────────────────────────

    /**
     * Store an uploaded file and create a ChatMessage record.
     */
    public static function uploadAttachment(int $threadId, int $senderUserId, UploadedFile $file): ChatMessage
    {
        $sender     = User::find($senderUserId);
        $senderName = $sender->Name ?? 'Unknown';

        $ext      = $file->getClientOriginalExtension();
        $filename = time() . '_' . Str::random(8) . ($ext ? '.' . $ext : '');
        $path     = $file->storeAs("chat-attachments/{$threadId}", $filename, 'public');

        $mime        = $file->getMimeType() ?? '';
        $messageType = str_starts_with($mime, 'image/') ? 'image' : 'file';

        return DB::transaction(function () use (
            $threadId, $senderUserId, $senderName, $path, $mime, $file, $messageType
        ) {
            $msg = ChatMessage::create([
                'thread_id'            => $threadId,
                'sender_user_id'       => $senderUserId,
                'sender_name_snapshot' => $senderName,
                'body'                 => $file->getClientOriginalName(),
                'message_type'         => $messageType,
                'media_url'            => $path,
                'media_type'           => $mime,
                'media_name'           => $file->getClientOriginalName(),
                'created_at'           => Carbon::now(),
            ]);

            ChatThread::where('id', $threadId)->update([
                'last_message_id' => $msg->id,
                'last_message_at' => $msg->created_at,
            ]);

            ChatThreadMember::where('thread_id', $threadId)
                ->where('user_id', $senderUserId)
                ->update([
                    'last_read_message_id' => $msg->id,
                    'last_read_at'         => $msg->created_at,
                ]);

            return $msg;
        });
    }

    // ── Group management ───────────────────────────────────────────

    public static function getMembers(int $threadId): array
    {
        return ChatThreadMember::where('thread_id', $threadId)
            ->whereNull('left_at')
            ->with('user:id,Name,AvatarUrl')
            ->orderBy('joined_at')
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->user_id,
                'name'       => $m->user->Name ?? '',
                'avatar_url' => self::publicAvatarUrl($m->user->AvatarUrl ?? null),
                'role'       => $m->role,
                'joined_at'  => $m->joined_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public static function updateGroupName(int $threadId, int $userId, string $authRole, string $name): void
    {
        $thread = ChatThread::find($threadId);
        if (!$thread || $thread->type !== 'group') {
            abort(404, 'Group not found');
        }

        $member = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if (!$member || ($member->role !== 'owner' && $authRole !== 'director' && $authRole !== 'super_admin')) {
            abort(403, 'Only the group owner or a director can rename this group');
        }

        $thread->name = $name;
        $thread->save();
    }

    public static function addMembers(int $threadId, int $userId, string $authRole, array $newUserIds): void
    {
        $thread = ChatThread::find($threadId);
        if (!$thread || $thread->type !== 'group') {
            abort(404, 'Group not found');
        }

        $member = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if (!$member || ($member->role !== 'owner' && $authRole !== 'director' && $authRole !== 'super_admin')) {
            abort(403, 'Only the group owner or a director can add members');
        }

        $now = Carbon::now();
        foreach ($newUserIds as $uid) {
            ChatThreadMember::updateOrCreate(
                ['thread_id' => $threadId, 'user_id' => (int) $uid],
                ['role' => 'member', 'joined_at' => $now, 'left_at' => null]
            );
        }
    }

    public static function removeMember(int $threadId, int $actorId, string $authRole, int $targetUserId): void
    {
        $thread = ChatThread::find($threadId);
        if (!$thread || $thread->type !== 'group') {
            abort(404, 'Group not found');
        }

        $actor = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $actorId)
            ->whereNull('left_at')
            ->first();

        if (!$actor || ($actor->role !== 'owner' && $authRole !== 'director' && $authRole !== 'super_admin')) {
            abort(403, 'Only the group owner or a director can remove members');
        }

        $target = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $targetUserId)
            ->whereNull('left_at')
            ->first();

        if (!$target) {
            abort(404, 'Member not found');
        }

        if ($target->role === 'owner') {
            abort(422, 'Cannot remove the group owner');
        }

        $target->left_at = Carbon::now();
        $target->save();
    }

    public static function leaveThread(int $threadId, int $userId): void
    {
        $member = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if (!$member) {
            abort(404, 'Membership not found');
        }

        if ($member->role === 'owner') {
            // Try to find another active member to promote
            $nextMember = ChatThreadMember::where('thread_id', $threadId)
                ->where('user_id', '!=', $userId)
                ->whereNull('left_at')
                ->orderBy('joined_at')
                ->first();

            if ($nextMember) {
                $nextMember->role = 'owner';
                $nextMember->save();
            } else {
                // No other members — delete the thread entirely
                DB::transaction(function () use ($threadId) {
                    ChatMessage::where('thread_id', $threadId)->delete();
                    ChatThreadMember::where('thread_id', $threadId)->delete();
                    ChatThread::where('id', $threadId)->delete();
                });
                return;
            }
        }

        $member->left_at = Carbon::now();
        $member->save();
    }

    public static function setPinned(int $threadId, int $userId, bool $pinned): void
    {
        ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->update(['is_pinned' => $pinned]);
    }

    public static function transferOwner(int $threadId, int $userId, int $newOwnerId): void
    {
        $current = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if (!$current || $current->role !== 'owner') {
            abort(403, 'Only the current owner can transfer ownership');
        }

        $newOwner = ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $newOwnerId)
            ->whereNull('left_at')
            ->first();

        if (!$newOwner) {
            abort(404, 'Target member not found');
        }

        DB::transaction(function () use ($current, $newOwner) {
            $current->role = 'member';
            $current->save();
            $newOwner->role = 'owner';
            $newOwner->save();
        });
    }

    // ── Read / Unread ──────────────────────────────────────────────

    public static function markRead(int $threadId, int $userId, ?int $messageId = null): void
    {
        if (!$messageId) {
            $last      = ChatMessage::where('thread_id', $threadId)->orderByDesc('id')->first();
            $messageId = $last?->id;
        }

        if ($messageId) {
            ChatThreadMember::where('thread_id', $threadId)
                ->where('user_id', $userId)
                ->update([
                    'last_read_message_id' => $messageId,
                    'last_read_at'         => Carbon::now(),
                ]);
        }
    }

    public static function totalUnread(int $userId, array $campusIds): int
    {
        $memberships = ChatThreadMember::where('user_id', $userId)
            ->whereNull('left_at')
            ->get();

        if ($memberships->isEmpty()) {
            return 0;
        }

        $total = 0;
        foreach ($memberships as $m) {
            if (!empty($campusIds)) {
                $thread = ChatThread::find($m->thread_id);
                if (!$thread || !in_array($thread->CampusID, $campusIds, true)) {
                    continue;
                }
            }
            $lastRead = $m->last_read_message_id ?? 0;
            $total   += ChatMessage::where('thread_id', $m->thread_id)
                ->where('id', '>', $lastRead)
                ->count();
        }

        return $total;
    }

    // ── Membership checks ──────────────────────────────────────────

    public static function isMember(int $threadId, int $userId): bool
    {
        return ChatThreadMember::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    public static function threadInCampus(int $threadId, array $campusIds): bool
    {
        if (empty($campusIds)) {
            return true; // super_admin
        }
        return ChatThread::where('id', $threadId)
            ->whereIn('CampusID', $campusIds)
            ->exists();
    }
}
