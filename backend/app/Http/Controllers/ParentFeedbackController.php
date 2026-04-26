<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ParentFeedback;
use App\Models\ParentSession;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
