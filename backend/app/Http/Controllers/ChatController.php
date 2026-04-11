<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageCreated;
use App\Models\ChatThread;
use App\Models\ChatThreadMember;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    // ── Thread list ────────────────────────────────────────────────

    public function threads(Request $request)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $perPage   = (int) $request->input('per_page', 30);

        return response()->json(ChatService::listThreads($userId, $campusIds, $perPage));
    }

    // ── Thread creation ────────────────────────────────────────────

    public function createDm(Request $request)
    {
        $request->validate([
            'other_user_id' => 'required|integer',
            'branch_id'     => 'required|integer',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $branchId  = (int) $request->input('branch_id');

        if (!empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $otherUserId = (int) $request->input('other_user_id');
        if ($otherUserId === $userId) {
            return response()->json(['message' => 'Cannot chat with yourself'], 422);
        }

        $otherUser = User::find($otherUserId);
        if (!$otherUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $thread = ChatService::findOrCreateDm($branchId, $userId, $otherUserId);

        return response()->json([
            'id'        => $thread->id,
            'type'      => $thread->type,
            'campus_id' => $thread->CampusID,
        ], 201);
    }

    public function createGroup(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:100',
            'member_ids'    => 'required|array|min:1',
            'member_ids.*'  => 'integer',
            'branch_id'     => 'required|integer',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $branchId  = (int) $request->input('branch_id');

        if (!empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $thread = ChatService::createGroup(
            $branchId,
            $userId,
            $request->input('name'),
            $request->input('member_ids')
        );

        return response()->json([
            'id'        => $thread->id,
            'type'      => $thread->type,
            'name'      => $thread->name,
            'campus_id' => $thread->CampusID,
        ], 201);
    }

    // ── Delete thread ──────────────────────────────────────────────

    public function deleteThread(Request $request, $threadId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $authRole = $request->attributes->get('auth_role', 'teacher');
        ChatService::deleteThread($threadId, $userId, $authRole);

        return response()->json(['ok' => true]);
    }

    // ── Messages ───────────────────────────────────────────────────

    public function messages(Request $request, $threadId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $beforeId = $request->input('before_id') ? (int) $request->input('before_id') : null;
        $limit    = min((int) ($request->input('limit') ?? 40), 100);

        return response()->json([
            'data' => ChatService::getMessages($threadId, $beforeId, $limit),
        ]);
    }

    public function sendMessage(Request $request, $threadId)
    {
        $request->validate([
            'body'                 => 'nullable|string|max:5000',
            'reply_to_message_id'  => 'nullable|integer',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $body = trim($request->input('body', ''));
        if ($body === '') {
            return response()->json(['message' => 'Message body is required'], 422);
        }

        $replyToId = $request->input('reply_to_message_id')
            ? (int) $request->input('reply_to_message_id')
            : null;

        $msg = ChatService::sendMessage($threadId, $userId, $body, $replyToId);

        $payload = ChatService::messageToArray($msg);

        try {
            broadcast(new ChatMessageCreated($threadId, $payload))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Chat] Broadcast failed: ' . $e->getMessage());
        }

        return response()->json($payload, 201);
    }

    // ── Delete message ─────────────────────────────────────────────

    public function deleteMessage(Request $request, $messageId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $messageId = (int) $messageId;
        $msg       = \App\Models\ChatMessage::find($messageId);
        if (!$msg) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $campusIds = $this->resolveCampusIds($request);
        if (!ChatService::threadInCampus($msg->thread_id, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($msg->thread_id, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $updated = ChatService::softDeleteMessage($messageId, $userId);
        $payload = ChatService::messageToArray($updated);

        try {
            broadcast(new ChatMessageCreated($msg->thread_id, $payload))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Chat] Broadcast failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'message' => $payload]);
    }

    // ── Attachment upload ──────────────────────────────────────────

    public function uploadAttachment(Request $request, $threadId)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:20480',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $msg     = ChatService::uploadAttachment($threadId, $userId, $request->file('file'));
        $payload = ChatService::messageToArray($msg);

        try {
            broadcast(new ChatMessageCreated($threadId, $payload))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Chat] Broadcast failed: ' . $e->getMessage());
        }

        return response()->json($payload, 201);
    }

    // ── Mark read ──────────────────────────────────────────────────

    public function markRead(Request $request, $threadId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $threadId = (int) $threadId;
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messageId = $request->input('message_id') ? (int) $request->input('message_id') : null;
        ChatService::markRead($threadId, $userId, $messageId);

        return response()->json(['ok' => true]);
    }

    // ── Unread count ───────────────────────────────────────────────

    public function unreadCount(Request $request)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);

        return response()->json([
            'unread_count' => ChatService::totalUnread($userId, $campusIds),
        ]);
    }

    // ── Group management ───────────────────────────────────────────

    public function getMembers(Request $request, $threadId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['data' => ChatService::getMembers($threadId)]);
    }

    public function updateThread(Request $request, $threadId)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $authRole = $request->attributes->get('auth_role', 'teacher');
        $name     = $request->input('name');
        ChatService::updateGroupName($threadId, $userId, $authRole, $name);

        return response()->json(['ok' => true, 'name' => $name]);
    }

    public function addMembers(Request $request, $threadId)
    {
        $request->validate([
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $authRole = $request->attributes->get('auth_role', 'teacher');
        ChatService::addMembers($threadId, $userId, $authRole, $request->input('user_ids'));

        return response()->json(['ok' => true]);
    }

    public function removeMember(Request $request, $threadId, $targetUserId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds    = $this->resolveCampusIds($request);
        $threadId     = (int) $threadId;
        $targetUserId = (int) $targetUserId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $authRole = $request->attributes->get('auth_role', 'teacher');
        ChatService::removeMember($threadId, $userId, $authRole, $targetUserId);

        return response()->json(['ok' => true]);
    }

    public function leaveThread(Request $request, $threadId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        ChatService::leaveThread($threadId, $userId);

        return response()->json(['ok' => true]);
    }

    public function pinThread(Request $request, $threadId)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $pinned = (bool) $request->input('pinned', true);
        ChatService::setPinned($threadId, $userId, $pinned);

        return response()->json(['ok' => true, 'is_pinned' => $pinned]);
    }

    public function transferOwner(Request $request, $threadId)
    {
        $request->validate([
            'new_owner_id' => 'required|integer',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $threadId  = (int) $threadId;

        if (!ChatService::threadInCampus($threadId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (!ChatService::isMember($threadId, $userId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        ChatService::transferOwner($threadId, $userId, (int) $request->input('new_owner_id'));

        return response()->json(['ok' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function resolveUserId(Request $request): ?int
    {
        $authUser = $request->attributes->get('auth_user');
        return $authUser?->id ? (int) $authUser->id : null;
    }

    private function resolveCampusIds(Request $request): array
    {
        $role = $request->attributes->get('auth_role');
        if ($role === 'super_admin') {
            return [];
        }
        $campusIds = array_map('intval', $request->attributes->get('auth_campus_ids', []));

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId > 0 && !empty($campusIds)) {
            if (!in_array($branchId, $campusIds, true)) {
                abort(403, 'Forbidden');
            }
            return [$branchId];
        }

        return $campusIds;
    }
}
