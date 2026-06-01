<?php

namespace App\Http\Controllers;

use App\Models\LearningRecord;
use App\Models\LearningRecordFeedback;
use App\Models\LearningRecordFeedbackReply;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\FeedbackPushNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningRecordFeedbackController extends Controller
{
    public function parentShow(Request $request, LearningRecord $learningRecord)
    {
        $session = $this->resolveParentSession($request);
        if (!$session) return response()->json(['message' => 'Unauthorized'], 401);
        $auth = $this->authorizeParentRecord($session, $learningRecord);
        if ($auth !== true) return $auth;

        $feedback = $learningRecord->feedback;
        // 先以「讀取前」狀態組出回應（含新回覆紅點），再標記家長已讀，避免清掉本次紅點。
        $payload = $this->formatParentFeedback($feedback);
        if ($feedback) {
            DB::table($feedback->getTable())->where('id', $feedback->id)->update(['last_read_by_parent_at' => now()]);
        }

        return response()->json(['feedback' => $payload]);
    }

    /**
     * 家長追問：在既有回饋上追加一則家長回覆，並 touch feedback 讓員工重新未讀。
     */
    public function parentReply(Request $request, LearningRecord $learningRecord)
    {
        $session = $this->resolveParentSession($request);
        if (!$session) return response()->json(['message' => 'Unauthorized'], 401);
        $auth = $this->authorizeParentRecord($session, $learningRecord);
        if ($auth !== true) return $auth;

        $feedback = $learningRecord->feedback;
        if (!$feedback) {
            return response()->json(['message' => '尚未有回饋可回覆，請先送出回饋'], 409);
        }

        $content = trim((string) $request->input('content', ''));
        if ($content === '' || mb_strlen($content) > 500) {
            return response()->json(['message' => '內容需為 1-500 字'], 422);
        }

        $reply = LearningRecordFeedbackReply::create([
            'feedback_id' => $feedback->id,
            'author_user_id' => null,
            'author_role' => 'parent',
            'parent_session_id' => $session->id,
            'content' => $content,
        ]);

        // 家長追問 → touch updated_at 觸發員工未讀；家長自己視為已讀。
        DB::table($feedback->getTable())
            ->where('id', $feedback->id)
            ->update(['updated_at' => now(), 'last_read_by_parent_at' => now()]);

        app(FeedbackPushNotifier::class)->notifyParentReplied($feedback);

        return response()->json([
            'reply' => $this->formatReply($reply),
            'message' => '已送出',
        ]);
    }

    /**
     * 員工（老師/主任）回覆家長。不 touch updated_at（避免讓其他員工產生假未讀）；
     * 家長端紅點改以「員工回覆 created_at > last_read_by_parent_at」判定。
     */
    public function staffReply(Request $request, LearningRecordFeedback $feedback)
    {
        $auth = $this->authorizeStaffFeedback($request, $feedback);
        if ($auth !== true) return $auth;

        $content = trim((string) $request->input('content', ''));
        if ($content === '' || mb_strlen($content) > 1000) {
            return response()->json(['message' => '回覆內容需為 1-1000 字'], 422);
        }

        $role = (string) $request->attributes->get('auth_role');
        $authUser = $request->attributes->get('auth_user');
        $replierRole = $role === 'teacher' ? 'teacher' : 'director';

        $reply = LearningRecordFeedbackReply::create([
            'feedback_id' => $feedback->id,
            'author_user_id' => $authUser ? (int) $authUser->id : null,
            'author_role' => $replierRole,
            'content' => $content,
        ]);

        // 回覆者該角色標記已讀（不影響另一員工角色、不 touch updated_at）。
        $readCol = $replierRole === 'teacher' ? 'last_read_by_teacher_at' : 'last_read_by_director_at';
        DB::table($feedback->getTable())->where('id', $feedback->id)->update([$readCol => now()]);

        app(FeedbackPushNotifier::class)->notifyStaffReplied($feedback);

        return response()->json([
            'reply' => $this->formatReply($reply),
            'message' => '已回覆家長',
        ]);
    }

    /**
     * 員工端取得某筆回饋的回覆串（modal 內顯示/重新整理用）。
     */
    public function replies(Request $request, LearningRecordFeedback $feedback)
    {
        $auth = $this->authorizeStaffFeedback($request, $feedback);
        if ($auth !== true) return $auth;

        return response()->json(['data' => $this->serializeReplies($feedback)]);
    }

    public function parentUpsert(Request $request, LearningRecord $learningRecord)
    {
        $session = $this->resolveParentSession($request);
        if (!$session) return response()->json(['message' => 'Unauthorized'], 401);
        $auth = $this->authorizeParentRecord($session, $learningRecord);
        if ($auth !== true) return $auth;

        $content = trim((string) $request->input('content', ''));
        if ($content === '' || mb_strlen($content) > 500) {
            return response()->json(['message' => '回饋內容需為 1-500 字'], 422);
        }

        $ctx = $this->recordContext($learningRecord);
        if (!$ctx) return response()->json(['message' => 'Learning record context missing'], 409);

        $feedback = LearningRecordFeedback::updateOrCreate(
            ['learning_record_id' => $learningRecord->id],
            [
                'student_id' => $ctx['student_id'],
                'student_class_id' => $ctx['student_class_id'],
                'class_session_id' => $ctx['class_session_id'],
                'teacher_id' => $ctx['teacher_id'],
                'campus_id' => $ctx['campus_id'],
                'content' => $content,
                'parent_session_id' => $session->id,
                'last_read_by_teacher_at' => null,
                'last_read_by_director_at' => null,
            ]
        );

        app(FeedbackPushNotifier::class)->notifyParentSubmitted($feedback);

        return response()->json([
            'feedback' => $this->formatParentFeedback($feedback),
            'message' => '已送出給老師',
        ]);
    }

    public function index(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        $query = LearningRecordFeedback::query()
            ->with('learningRecord')
            ->orderBy('updated_at', 'desc');

        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if (!$teacherId) return response()->json(['message' => 'Teacher not linked'], 403);
            $query->where('teacher_id', $teacherId);
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            $branchId = (int) ($request->input('branch_id') ?: $request->input('campus_id'));
            if ($branchId > 0) {
                if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
                    return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
                }
                $query->where('campus_id', $branchId);
            } elseif ($role !== 'super_admin') {
                if (empty($campusIds)) return response()->json(['message' => 'Campus required'], 403);
                $query->whereIn('campus_id', $campusIds);
            }
            if ($request->filled('teacher_id')) {
                $query->where('teacher_id', (int) $request->input('teacher_id'));
            }
        }

        if ($request->boolean('unread')) {
            $col = $role === 'teacher' ? 'last_read_by_teacher_at' : 'last_read_by_director_at';
            $query->where(fn ($q) => $q->whereNull($col)->orWhereColumn($col, '<', 'updated_at'));
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $page = $query->paginate($perPage);
        $items = $page->getCollection()->map(fn ($f) => $this->formatStaffFeedback($f));

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function markRead(Request $request, LearningRecordFeedback $feedback)
    {
        $role = (string) $request->attributes->get('auth_role');
        $readAt = now();
        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId !== (int) $feedback->teacher_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            DB::table($feedback->getTable())
                ->where('id', $feedback->id)
                ->update(['last_read_by_teacher_at' => $readAt]);
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            if ($role !== 'super_admin' && !in_array((int) $feedback->campus_id, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            DB::table($feedback->getTable())
                ->where('id', $feedback->id)
                ->update(['last_read_by_director_at' => $readAt]);
        }

        return response()->json(['message' => 'ok']);
    }

    public function unreadCount(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        $query = LearningRecordFeedback::query();

        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if (!$teacherId) return response()->json(['message' => 'Teacher not linked'], 403);
            $query->where('teacher_id', $teacherId);
            $readAtColumn = 'last_read_by_teacher_at';
        } else {
            $campusIds = $role === 'super_admin' ? [] : array_map('intval', $request->attributes->get('auth_campus_ids', []));
            $branchId = (int) ($request->input('branch_id') ?: $request->input('campus_id'));
            if ($branchId > 0) {
                if ($role !== 'super_admin' && !in_array($branchId, $campusIds, true)) {
                    return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
                }
                $query->where('campus_id', $branchId);
            } elseif ($role !== 'super_admin') {
                if (empty($campusIds)) return response()->json(['message' => 'Campus required'], 403);
                $query->whereIn('campus_id', $campusIds);
            }
            $readAtColumn = 'last_read_by_director_at';
        }

        $count = $query
            ->where(fn ($q) => $q->whereNull($readAtColumn)->orWhereColumn($readAtColumn, '<', 'updated_at'))
            ->count();

        return response()->json(['count' => (int) $count]);
    }

    public function analytics(Request $request)
    {
        $role = (string) $request->attributes->get('auth_role');
        $days = min(30, max(1, (int) $request->input('days', 7)));
        $windowStart = Carbon::now()->subDays($days - 1)->startOfDay();
        $windowEnd = Carbon::now()->endOfDay();
        $branchId = (int) ($request->input('branch_id') ?: $request->input('campus_id'));

        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if ($role !== 'super_admin' && $role !== 'teacher' && empty($campusIds)) {
            return response()->json(['message' => 'Campus required'], 403);
        }
        if ($role !== 'super_admin' && $role !== 'teacher' && $branchId > 0 && !in_array($branchId, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
        }

        $teacherId = 0;
        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId <= 0) {
                return response()->json(['message' => 'Teacher not linked'], 403);
            }
        }

        $approvedQuery = DB::table('LearningRecord as lr')
            ->join('Student as st', 'st.id', '=', 'lr.StudentID')
            ->where('lr.Status', 'approved')
            ->whereNotNull('lr.ApprovedAt')
            ->whereBetween('lr.ApprovedAt', [$windowStart, $windowEnd]);

        if ($teacherId > 0) {
            $approvedQuery->where('lr.TeacherID', $teacherId);
        } elseif ($role !== 'super_admin') {
            if ($branchId > 0) {
                $approvedQuery->where('st.CampusID', $branchId);
            } else {
                $approvedQuery->whereIn('st.CampusID', $campusIds);
            }
        } elseif ($branchId > 0) {
            $approvedQuery->where('st.CampusID', $branchId);
        }

        if ($branchId > 0 && $teacherId > 0) {
            $approvedQuery->where('st.CampusID', $branchId);
        }

        $approvedRows = $approvedQuery->get([
            'lr.id as learning_record_id',
            'lr.TeacherID as teacher_id',
            'st.CampusID as campus_id',
            'lr.SessionDate as session_date',
            'lr.Subject as subject',
            'lr.ApprovedAt as approved_at',
            'st.name as student_name',
        ]);

        $approvedTotal = $approvedRows->count();
        if ($approvedTotal === 0) {
            return response()->json([
                'data' => [
                    'window' => ['start' => $windowStart->toDateString(), 'end' => $windowEnd->toDateString(), 'days' => $days],
                    'summary' => [
                        'approved_records' => 0,
                        'replied_records' => 0,
                        'reply_rate_pct' => 0.0,
                        'unreplied_records' => 0,
                        'new_replies_unread' => 0,
                    ],
                    'by_teacher' => [],
                    'pending_preview' => [],
                ],
                'meta' => ['scope_role' => $role, 'branch_id' => $branchId > 0 ? $branchId : null],
            ]);
        }

        $approvedIds = $approvedRows->pluck('learning_record_id')->map(fn ($id) => (int) $id)->all();
        $feedbackRows = LearningRecordFeedback::query()
            ->whereIn('learning_record_id', $approvedIds)
            ->get(['learning_record_id', 'teacher_id', 'last_read_by_teacher_at', 'last_read_by_director_at', 'updated_at']);

        $repliedIds = $feedbackRows->pluck('learning_record_id')->map(fn ($id) => (int) $id)->unique()->values();
        $repliedTotal = $repliedIds->count();
        $unrepliedSet = array_fill_keys(array_diff($approvedIds, $repliedIds->all()), true);
        $unrepliedTotal = count($unrepliedSet);
        $replyRate = $approvedTotal > 0 ? round(($repliedTotal / $approvedTotal) * 100, 1) : 0.0;

        $unreadNew = $feedbackRows->filter(function ($row) use ($role) {
            if ($role === 'teacher') {
                return !$row->last_read_by_teacher_at || $row->last_read_by_teacher_at->lt($row->updated_at);
            }
            return !$row->last_read_by_director_at || $row->last_read_by_director_at->lt($row->updated_at);
        })->count();

        $approvedByTeacher = [];
        foreach ($approvedRows as $row) {
            $tid = (int) $row->teacher_id;
            if ($tid <= 0) {
                continue;
            }
            $approvedByTeacher[$tid] = ($approvedByTeacher[$tid] ?? 0) + 1;
        }
        $repliedByTeacher = [];
        foreach ($feedbackRows as $row) {
            $tid = (int) $row->teacher_id;
            if ($tid <= 0) {
                continue;
            }
            $repliedByTeacher[$tid] = ($repliedByTeacher[$tid] ?? 0) + 1;
        }

        $teacherIds = array_keys($approvedByTeacher);
        $teacherNames = empty($teacherIds)
            ? collect()
            : DB::table('User')->whereIn('id', $teacherIds)->pluck('Name', 'id');

        $byTeacher = collect($teacherIds)->map(function ($tid) use ($approvedByTeacher, $repliedByTeacher, $teacherNames) {
            $approved = (int) ($approvedByTeacher[$tid] ?? 0);
            $replied = (int) ($repliedByTeacher[$tid] ?? 0);
            $unreplied = max(0, $approved - $replied);
            return [
                'teacher_id' => (int) $tid,
                'teacher_name' => (string) ($teacherNames[$tid] ?? ('#' . $tid)),
                'approved_records' => $approved,
                'replied_records' => $replied,
                'unreplied_records' => $unreplied,
                'reply_rate_pct' => $approved > 0 ? round(($replied / $approved) * 100, 1) : 0.0,
            ];
        })
            ->sortBy([
                ['unreplied_records', 'desc'],
                ['reply_rate_pct', 'asc'],
                ['teacher_name', 'asc'],
            ])
            ->values()
            ->all();

        $pendingPreview = [];
        foreach ($approvedRows as $row) {
            if (!isset($unrepliedSet[(int) $row->learning_record_id])) {
                continue;
            }
            if (count($pendingPreview) >= 5) {
                break;
            }
            $pendingPreview[] = [
                'learning_record_id' => (int) $row->learning_record_id,
                'student_name' => (string) ($row->student_name ?? ''),
                'teacher_id' => (int) $row->teacher_id,
                'teacher_name' => (string) ($teacherNames[(int) $row->teacher_id] ?? ('#' . (int) $row->teacher_id)),
                'session_date' => (string) ($row->session_date ?? ''),
                'subject' => (string) ($row->subject ?? ''),
                'approved_at' => optional($row->approved_at)->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => [
                'window' => ['start' => $windowStart->toDateString(), 'end' => $windowEnd->toDateString(), 'days' => $days],
                'summary' => [
                    'approved_records' => $approvedTotal,
                    'replied_records' => $repliedTotal,
                    'reply_rate_pct' => $replyRate,
                    'unreplied_records' => $unrepliedTotal,
                    'new_replies_unread' => (int) $unreadNew,
                ],
                'by_teacher' => $byTeacher,
                'pending_preview' => $pendingPreview,
            ],
            'meta' => ['scope_role' => $role, 'branch_id' => $branchId > 0 ? $branchId : null],
        ]);
    }

    private function resolveParentSession(Request $request): ?ParentSession
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        return ParentSession::where('TokenHash', hash('sha256', $token))
            ->where('ExpiresAt', '>', Carbon::now())
            ->first();
    }

    private function authorizeParentRecord(ParentSession $session, LearningRecord $record)
    {
        $ctx = $this->recordContext($record);
        if (!$ctx) return response()->json(['message' => 'Learning record context missing'], 409);
        if ((int) $ctx['student_id'] !== (int) $session->StudentID) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($record->VoidedAt !== null || (string) $record->Status !== 'approved') {
            return response()->json(['message' => 'Only approved records can receive feedback'], 409);
        }

        return true;
    }

    private function recordContext(LearningRecord $record): ?array
    {
        $class = StudentClass::find($record->StudentClassID);
        $studentId = (int) ($record->StudentID ?: ($class->StudentID ?? 0));
        $student = $studentId > 0 ? Student::find($studentId) : null;
        if (!$class || !$student) return null;

        return [
            'student_id' => $studentId,
            'student_class_id' => (int) $record->StudentClassID,
            'class_session_id' => $record->ClassSessionID ? (int) $record->ClassSessionID : null,
            'teacher_id' => (int) $record->TeacherID,
            'campus_id' => (int) $student->CampusID,
        ];
    }

    /**
     * 員工端回覆/讀取的分校與師別授權，鏡像 markRead 的隔離邏輯。
     * teacher 僅限自己 teacher_id；director 限 campus_id ∈ auth_campus_ids；super_admin 全部。
     *
     * @return true|\Illuminate\Http\JsonResponse
     */
    private function authorizeStaffFeedback(Request $request, LearningRecordFeedback $feedback)
    {
        $role = (string) $request->attributes->get('auth_role');
        if ($role === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            if ($teacherId <= 0) return response()->json(['message' => 'Teacher not linked'], 403);
            if ($teacherId !== (int) $feedback->teacher_id) return response()->json(['message' => 'Forbidden'], 403);
            return true;
        }
        if ($role === 'super_admin') return true;
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if (!in_array((int) $feedback->campus_id, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return true;
    }

    private function serializeReplies(LearningRecordFeedback $f): array
    {
        return LearningRecordFeedbackReply::where('feedback_id', $f->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => $this->formatReply($r))
            ->all();
    }

    private function formatReply(LearningRecordFeedbackReply $r): array
    {
        $authorName = null;
        if ($r->author_role !== 'parent' && $r->author_user_id) {
            $authorName = DB::table('User')->where('id', $r->author_user_id)->value('Name');
        }
        return [
            'id' => (int) $r->id,
            'feedback_id' => (int) $r->feedback_id,
            'author_role' => (string) $r->author_role,
            'author_name' => $authorName,
            'content' => $r->content,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    /**
     * 家長端「有新回覆」紅點：存在員工回覆其 created_at 晚於 last_read_by_parent_at（或從未讀過）。
     */
    private function hasUnreadReplyForParent(LearningRecordFeedback $f, ?array $replies = null): bool
    {
        $replies = $replies ?? $this->serializeReplies($f);
        $lastRead = $f->last_read_by_parent_at;
        foreach ($replies as $r) {
            if (($r['author_role'] ?? '') === 'parent') continue;
            if (empty($r['created_at'])) continue;
            if (!$lastRead) return true;
            if (Carbon::parse($r['created_at'])->gt($lastRead)) return true;
        }
        return false;
    }

    private function formatParentFeedback(?LearningRecordFeedback $f): ?array
    {
        if (!$f) return null;
        $replies = $this->serializeReplies($f);
        return [
            'id' => (int) $f->id,
            'learning_record_id' => (int) $f->learning_record_id,
            'content' => $f->content,
            'created_at' => optional($f->created_at)->toIso8601String(),
            'updated_at' => optional($f->updated_at)->toIso8601String(),
            'replies' => $replies,
            'has_unread_reply' => $this->hasUnreadReplyForParent($f, $replies),
        ];
    }

    private function formatStaffFeedback(LearningRecordFeedback $f): array
    {
        $student = Student::find($f->student_id);
        $teacherName = \Illuminate\Support\Facades\DB::table('User')->where('id', $f->teacher_id)->value('Name');
        $record = $f->learningRecord;

        return [
            'id' => (int) $f->id,
            'learning_record_id' => (int) $f->learning_record_id,
            'student_id' => (int) $f->student_id,
            'student_name' => $student->name ?? null,
            'teacher_id' => (int) $f->teacher_id,
            'teacher_name' => $teacherName,
            'campus_id' => (int) $f->campus_id,
            'session_date' => $record?->SessionDate,
            'subject' => $record?->Subject,
            'content' => $f->content,
            'updated_at' => optional($f->updated_at)->toIso8601String(),
            'unread_for_teacher' => !$f->last_read_by_teacher_at || $f->last_read_by_teacher_at->lt($f->updated_at),
            'unread_for_director' => !$f->last_read_by_director_at || $f->last_read_by_director_at->lt($f->updated_at),
        ];
    }
}
