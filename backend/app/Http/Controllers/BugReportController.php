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
            // Content re-check (finfo) defeats a disguised-script-as-image bypass (#977 / CVE-2025-27515).
            'attachments.*' => ['file', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120', new \App\Rules\RealImageContent()],
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
        $attachmentErrors = 0;
        if (is_array($uploaded)) {
            $attachmentErrors = BugReportService::attachUploadedFiles($bug, $uploaded);
        }

        return response()->json([
            'id'               => $bug->id,
            'status'           => $bug->status,
            'created_at'       => $bug->created_at?->toIso8601String(),
            'attachment_errors' => $attachmentErrors,
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
            'reporter' => $isSuperAdmin ? $request->input('reporter') : null,
            'keyword' => $request->input('keyword'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'sort' => $request->input('sort'),
        ]);
        $perPage = min((int) $request->input('per_page', 20), 100);

        // #378: 一般 reporter（teacher/director）看自己的回報時，跨分校顯示
        // （行業慣例：GitHub/Linear/Jira/ServiceNow 都讓 requester 看自己的全部）。
        // 仍受限於使用者目前 auth_campus_ids（離開的分校 = 失去存取）。
        $reporterCampusIds = $this->resolveReporterCampusIds($request);

        if ($userId) {
            BugReportService::markReporterInboxSeenFromList(
                $userId,
                $isSuperAdmin ? $campusIds : $reporterCampusIds
            );
        }

        if ($seesAllBranchBugs) {
            return response()->json(BugReportService::listForAdmin($campusIds, $filters, $perPage));
        }

        return response()->json(BugReportService::listForUser($userId, $reporterCampusIds, $filters, $perPage));
    }

    public function show(Request $request, $id)
    {
        $userId = $this->resolveUserId($request);
        $role = $request->attributes->get('auth_role');
        $campusIds = $this->resolveCampusIds($request);
        $isSuperAdmin = $role === 'super_admin';
        $seesAllBranchBugs = $isSuperAdmin;

        $bugId = (int) $id;

        if (!$this->canAccessBug($request, $bugId)) {
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
        $isSuperAdmin = $role === 'super_admin';
        // #378: reporter 紅點計數要跨自己所有分校，否則切到 A 分校後 B 分校的回信變成「看不見的紅點」
        $campusIds = $isSuperAdmin
            ? $this->resolveCampusIds($request)
            : $this->resolveReporterCampusIds($request);

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
        $bugId = (int) $id;
        $isSuperAdmin = $role === 'super_admin';

        if (!$this->canAccessBug($request, $bugId)) {
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
        $bugId = (int) $id;

        if (!$this->canAccessBug($request, $bugId)) {
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

        $bugId = (int) $id;
        $commentIdInt = (int) $commentId;

        if (!$this->canAccessBug($request, $bugId)) {
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

    /**
     * Reporter confirms or rejects a resolved bug.
     * verdict=confirmed → closed; verdict=reopened → in_progress
     * Only the original reporter may call this; bug must be in 'resolved' state.
     */
    public function reporterVerify(Request $request, $id)
    {
        $request->validate([
            'verdict' => 'required|in:confirmed,reopened',
            'note'    => 'nullable|string|max:500',
        ]);

        $userId    = $this->resolveUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $bugId = (int) $id;

        if (!$this->canAccessBug($request, $bugId)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $detail = BugReportService::getDetail($bugId, false);
        if (!$detail) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ((int) $detail['reporter_user_id'] !== $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($detail['status'] !== 'resolved') {
            return response()->json(['message' => '只有狀態為「已解決」的回報才能驗收'], 422);
        }

        $newStatus = $request->input('verdict') === 'confirmed' ? 'closed' : 'in_progress';
        $note      = $request->input('note')
            ?: ($newStatus === 'closed' ? '回報者確認已修好' : '回報者反映問題仍存在');

        BugReportService::changeStatus($bugId, $userId, $newStatus, $note);

        return response()->json(['ok' => true, 'new_status' => $newStatus]);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * #378: reporter 自己的回報跨分校可存取（仍須仍隸屬於該分校），
     * 與 index/show 一致；避免 branch_id 查詢參數讓 reporter-verify 誤 404。
     */
    private function canAccessBug(Request $request, int $bugId): bool
    {
        $userId = $this->resolveUserId($request);
        $role = $request->attributes->get('auth_role');
        $isSuperAdmin = $role === 'super_admin';

        if ($isSuperAdmin) {
            return BugReportService::belongsToCampus($bugId, $this->resolveCampusIds($request));
        }

        $reporterCampusIds = $this->resolveReporterCampusIds($request);
        if ($userId && BugReportService::belongsToCampusForReporter($bugId, $userId, $reporterCampusIds)) {
            return true;
        }

        return BugReportService::belongsToCampus($bugId, $this->resolveCampusIds($request));
    }

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
                // Authorization is unchanged — the user still cannot act on a campus
                // outside their approved set. But the response is now actionable instead
                // of an opaque "Forbidden": this is the failure teachers hit when the
                // bug-report widget tags a branch the account is not authorized for
                // (e.g. a 新莊 teacher whose UserCampus row for that branch is pending).
                // See #1055.
                abort(response()->json([
                    'message' => '無法存取分校 #' . $branchId . '：此帳號未獲授權存取該分校。'
                        . '請改用您所屬分校操作，或聯繫管理員開通權限。',
                    'code' => 'campus_not_authorized',
                    'branch_id' => $branchId,
                    'allowed_campus_ids' => array_values($campusIds),
                ], 403));
            }
            return [$branchId];
        }

        return $campusIds;
    }

    /**
     * #378: 「我自己的 bug 回報」永遠以使用者全部 auth_campus_ids 計算，
     * 忽略 branch_id query。這是 reporter-mode 視角，與 store/super_admin 視角不同。
     *
     * 仍受 auth_campus_ids 限制（離開的分校不可看），避免越權。
     */
    private function resolveReporterCampusIds(Request $request): array
    {
        $role = $request->attributes->get('auth_role');
        if ($role === 'super_admin') {
            return [];
        }
        return array_map('intval', $request->attributes->get('auth_campus_ids', []));
    }
}
