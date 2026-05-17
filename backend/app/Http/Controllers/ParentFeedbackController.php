<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ParentFeedback;
use App\Models\ParentFeedbackReply;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ParentFeedbackController extends Controller
{
    private const CATEGORIES = ['schedule', 'teaching', 'system', 'other'];

    // ─── 家長端：送出建議回饋 ─────────────────────────────────────────

    public function store(Request $request)
    {
        $session = $this->resolveParentSession($request);
        if (!$session) {
            return response()->json(['message' => '請重新登入'], 401);
        }

        $validated = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'content'  => ['required', 'string', 'min:10', 'max:500'],
            'rating'   => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $student = Student::find($session->StudentID);
        if (!$student) {
            return response()->json(['message' => '找不到學生資料'], 404);
        }

        ParentFeedback::create([
            'student_id' => $student->id,
            'campus_id'  => $student->CampusID,
            'category'   => $validated['category'],
            'content'    => $validated['content'],
            'rating'     => $validated['rating'] ?? null,
            'is_read'    => false,
        ]);

        return response()->json(['message' => '感謝您的寶貴建議！全真團隊將認真參考。'], 201);
    }

    // ─── Super Admin 端：查詢 ─────────────────────────────────────────

    public function index(Request $request)
    {
        $campusId = $request->query('campus_id');
        $category = $request->query('category');

        $query = ParentFeedback::with('student:id,name')
            ->when($campusId, fn ($q) => $q->where('campus_id', $campusId))
            ->when($category && in_array($category, self::CATEGORIES), fn ($q) => $q->where('category', $category))
            ->latest()
            ->paginate(30);

        $query->getCollection()->transform(function ($fb) {
            return [
                'id'           => $fb->id,
                'student_name' => $fb->student?->name ?? '（已刪除）',
                'campus_id'    => $fb->campus_id,
                'category'     => $fb->category,
                'rating'       => $fb->rating,
                'content'      => $fb->content,
                'is_read'      => $fb->is_read,
                'created_at'   => $fb->created_at?->toDateTimeString(),
            ];
        });

        return response()->json($query);
    }

    public function markRead(Request $request, int $id)
    {
        $fb = ParentFeedback::findOrFail($id);
        $fb->update(['is_read' => true]);
        return response()->json(['message' => 'ok']);
    }

    public function unreadCount(Request $request)
    {
        $campusId = $request->query('campus_id');

        $count = ParentFeedback::where('is_read', false)
            ->when($campusId, fn ($q) => $q->where('campus_id', $campusId))
            ->count();

        return response()->json(['count' => $count]);
    }

    // ─── Teacher/Director 端：查看自己學生的回饋（#409） ───────────────

    public function forTeacher(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $role = $request->attributes->get('auth_role', 'teacher');
        $status = $request->query('status', 'all');
        $limit = min(50, max(1, (int) $request->input('limit', 20)));

        if ($role === 'teacher') {
            $studentIds = StudentClass::where('TeacherID', (int) $user->id)
                ->where('Stop', 0)
                ->pluck('StudentID')
                ->unique()
                ->values()
                ->all();
        } else {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            $studentIds = Student::whereIn('CampusID', $campusIds)->pluck('id')->all();
        }

        if (empty($studentIds)) {
            return response()->json(['items' => [], 'unread_count' => 0]);
        }

        $query = ParentFeedback::with('student:id,name')
            ->whereIn('student_id', $studentIds)
            ->latest();

        if ($status === 'unread') {
            $query->where(function ($q) {
                $q->where('is_read', false)->orWhereNull('read_at');
            });
        }

        $items = $query->limit($limit)->get()->map(fn ($fb) => [
            'id' => $fb->id,
            'student_id' => (int) $fb->student_id,
            'student_name' => $fb->student?->name ?? '',
            'category' => $fb->category,
            'content' => $fb->content,
            'rating' => $fb->rating,
            'is_read' => (bool) $fb->is_read,
            'created_at' => $fb->created_at?->toIso8601String(),
        ]);

        $unreadCount = ParentFeedback::whereIn('student_id', $studentIds)
            ->where(function ($q) {
                $q->where('is_read', false)->orWhereNull('read_at');
            })
            ->count();

        return response()->json(['items' => $items, 'unread_count' => $unreadCount]);
    }

    public function markReadByTeacher(Request $request, int $id)
    {
        $fb = ParentFeedback::findOrFail($id);
        $fb->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['message' => 'ok']);
    }

    // ─── Reply mechanism (#410) ─────────────────────────────────────

    public function reply(Request $request, int $id)
    {
        $request->validate([
            'content' => 'required|string|min:2|max:1000',
        ]);

        $fb = ParentFeedback::findOrFail($id);
        $user = $request->attributes->get('auth_user');
        $role = $request->attributes->get('auth_role', 'teacher');

        if (!Schema::hasTable('parent_feedback_replies')) {
            return response()->json(['message' => 'Reply feature not available yet'], 503);
        }

        $reply = ParentFeedbackReply::create([
            'feedback_id' => $fb->id,
            'user_id' => (int) $user->id,
            'replier_role' => in_array($role, ['director', 'super_admin']) ? 'director' : 'teacher',
            'content' => $request->input('content'),
        ]);

        if (!$fb->is_read) {
            $fb->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json([
            'id' => (int) $reply->id,
            'replier_name' => $user->Name,
            'replier_role' => $reply->replier_role,
            'content' => $reply->content,
            'created_at' => $reply->created_at->toIso8601String(),
        ], 201);
    }

    public function replies(Request $request, int $id)
    {
        if (!Schema::hasTable('parent_feedback_replies')) {
            return response()->json(['replies' => []]);
        }

        $replies = ParentFeedbackReply::with('user:id,Name')
            ->where('feedback_id', $id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'replier_name' => $r->user?->Name ?? '',
                'replier_role' => $r->replier_role,
                'content' => $r->content,
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return response()->json(['replies' => $replies]);
    }

    // ─── Private ──────────────────────────────────────────────────────

    private function resolveParentSession(Request $request): ?ParentSession
    {
        $auth = $request->header('Authorization');
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($auth, 7));
        if ($token === '') {
            return null;
        }
        return ParentSession::where('TokenHash', hash('sha256', $token))
            ->where('ExpiresAt', '>', Carbon::now())
            ->first();
    }
}
