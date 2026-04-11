<?php

namespace App\Http\Controllers;

use App\Services\BugReportService;
use Illuminate\Http\Request;

class BugReportController extends Controller
{
    public function store(Request $request)
    {
        $maxAtt = BugReportService::MAX_ATTACHMENTS;
        $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'severity' => 'nullable|in:low,medium,high,critical',
            'page_key' => 'nullable|string|max:50',
            'url' => 'nullable|string|max:500',
            'client_info' => 'nullable|string|max:2000',
            'branch_id' => 'required|integer',
            'attachments' => "nullable|array|max:{$maxAtt}",
            'attachments.*' => 'file|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $this->resolveCampusIds($request);
        $branchId = (int) $request->input('branch_id');

        if (!empty($campusIds) && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $bug = BugReportService::create([
            'CampusID' => $branchId,
            'reporter_user_id' => $userId,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'severity' => $request->input('severity', 'medium'),
            'status' => 'new',
            'page_key' => $request->input('page_key'),
            'url' => $request->input('url'),
            'client_info' => $request->input('client_info'),
        ]);

        $uploaded = $request->file('attachments', []);
        if ($uploaded instanceof \Illuminate\Http\UploadedFile) {
            $uploaded = [$uploaded];
        }
        if (is_array($uploaded)) {
            BugReportService::attachUploadedFiles($bug, $uploaded);
        }

        return response()->json([
            'id' => $bug->id,
            'status' => $bug->status,
            'created_at' => $bug->created_at?->toIso8601String(),
        ], 201);
    }

    public function index(Request $request)
    {
        $userId = $this->resolveUserId($request);
        $role = $request->attributes->get('auth_role');
        $campusIds = $this->resolveCampusIds($request);
        $isSuperAdmin = $role === 'super_admin';
        $seesAllBranchBugs = $isSuperAdmin;

        $filters = array_filter([
            'status' => $request->input('status'),
            'severity' => $request->input('severity'),
        ]);
        $perPage = (int) $request->input('per_page', 20);

        if ($userId) {
            BugReportService::markReporterInboxSeenFromList($userId, $campusIds);
        }

        if ($seesAllBranchBugs) {
            return response()->json(BugReportService::listForAdmin($campusIds, $filters, $perPage));
        }

        return response()->json(BugReportService::listForUser($userId, $campusIds, $filters, $perPage));
    }

    public function show(Request $request, $id)
    {
        $userId = $this->resolveUserId($request);
        $role = $request->attributes->get('auth_role');
        $campusIds = $this->resolveCampusIds($request);
        $isSuperAdmin = $role === 'super_admin';
        $seesAllBranchBugs = $isSuperAdmin;

        $bugId = (int) $id;

        if (!BugReportService::belongsToCampus($bugId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $detail = BugReportService::getDetail($bugId, $isSuperAdmin);
        if (!$detail) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$seesAllBranchBugs && (int) $detail['reporter_user_id'] !== (int) $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ((int) $detail['reporter_user_id'] === (int) $userId) {
            BugReportService::markBugRead($userId, $bugId);
        }

        return response()->json($detail);
    }

    public function unreadBadge(Request $request)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $role = (string) ($request->attributes->get('auth_role') ?? '');
        $campusIds = $this->resolveCampusIds($request);

        return response()->json([
            'unread_count' => BugReportService::unreadBadgeCount($userId, $role, $campusIds),
        ]);
    }

    public function markInboxSeen(Request $request)
    {
        $userId = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        BugReportService::markBugInboxSeenForSuperAdmin($userId);

        return response()->json(['ok' => true]);
    }

    public function addComment(Request $request, $id)
    {
        $request->validate([
            'body' => 'required|string|max:5000',
            'is_internal_note' => 'nullable|boolean',
        ]);

        $userId = $this->resolveUserId($request);
        $role = $request->attributes->get('auth_role');
        $campusIds = $this->resolveCampusIds($request);
        $bugId = (int) $id;
        $isSuperAdmin = $role === 'super_admin';

        if (!BugReportService::belongsToCampus($bugId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $detail = BugReportService::getDetail($bugId, true);
        if (!$detail) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $canComment = $isSuperAdmin || ((int) $detail['reporter_user_id'] === (int) $userId);
        if (!$canComment) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $isInternal = filter_var($request->input('is_internal_note', false), FILTER_VALIDATE_BOOLEAN);
        if ($isInternal && !$isSuperAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $comment = BugReportService::addComment($bugId, $userId, $request->input('body'), $isInternal);

        return response()->json([
            'id' => $comment->id,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:new,triaged,in_progress,resolved,closed',
            'note' => 'nullable|string|max:500',
        ]);

        $userId = $this->resolveUserId($request);
        $campusIds = $this->resolveCampusIds($request);
        $bugId = (int) $id;

        if (!BugReportService::belongsToCampus($bugId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $ok = BugReportService::changeStatus($bugId, $userId, $request->input('status'), $request->input('note'));
        if (!$ok) {
            return response()->json(['message' => 'Invalid status transition'], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function updateCommentVisibility(Request $request, $id, $commentId)
    {
        $request->validate([
            'is_internal_note' => 'required|boolean',
        ]);

        $campusIds = $this->resolveCampusIds($request);
        $bugId = (int) $id;
        $commentIdInt = (int) $commentId;

        if (!BugReportService::belongsToCampus($bugId, $campusIds)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $comment = BugReportService::updateCommentVisibility(
            $bugId,
            $commentIdInt,
            (bool) $request->boolean('is_internal_note')
        );
        if (!$comment) {
            return response()->json(['message' => 'Not found'], 404);
        }

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
