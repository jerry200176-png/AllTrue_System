<?php

namespace App\Http\Controllers;

use App\Models\{Student, StudentClass, SubjectCreditTransfer};
use App\Services\Billing\SubjectCreditMovement;
use App\Services\Billing\SubjectCreditPreview;
use Illuminate\Http\Request;

/** In-app #535: existing director/super-admin boundary; services recheck scope under locks. */
final class SubjectCreditTransferController extends Controller
{
    public function index(Request $request, StudentClass $studentClass)
    {
        $this->authorizedCampuses($request, $studentClass);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        if (!SubjectCreditTransfer::storageReady()) {
            return response()->json(['message' => '轉用紀錄尚未完成資料結構更新，請稍後再試。'], 503);
        }
        $student = Student::findOrFail($studentClass->StudentID);
        // Source, destination template and new batch all retain access to the same narrow history.
        return SubjectCreditTransfer::where('student_id', $student->id)->where('campus_id', $student->CampusID)
            ->where(fn ($q) => $q->where('source_student_class_id', $studentClass->ID)
                ->orWhere('template_student_class_id', $studentClass->ID)->orWhere('target_student_class_id', $studentClass->ID))
            ->orderByDesc('id')->paginate(20, ['id', 'source_student_class_id', 'template_student_class_id',
                'target_student_class_id', 'sessions', 'amount', 'unit_price', 'lesson_minutes', 'reason',
                'created_by', 'created_at', 'reversed_at', 'reversed_by', 'reverse_reason'])
            ->through(fn ($r) => ['id' => (int) $r->id, 'source_course_id' => (int) $r->source_student_class_id,
                'destination_template_course_id' => (int) $r->template_student_class_id, 'target_course_id' => (int) $r->target_student_class_id,
                'moved_sessions' => (int) $r->sessions, 'amount' => (int) $r->amount, 'unit_price' => $r->unit_price,
                'lesson_minutes' => (int) $r->lesson_minutes, 'reason' => $r->reason, 'created_by' => (int) $r->created_by,
                'created_at' => $r->created_at, 'reversed' => $r->reversed_at !== null, 'reversed_at' => $r->reversed_at,
                'reversed_by' => $r->reversed_by, 'reverse_reason' => $r->reverse_reason]);
    }

    public function preview(Request $request, StudentClass $studentClass, SubjectCreditPreview $preview)
    {
        $data = $request->validate([
            'target_course_id' => ['required', 'integer', 'min:1'],
            'sessions' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        // Explicit super-admin exception; all other actors, including empty scope, are bounded.
        $campusIds = $request->attributes->get('auth_role') === 'super_admin'
            ? null : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        return response()->json($preview->run($studentClass, $data, $campusIds));
    }

    public function apply(Request $request, StudentClass $studentClass, SubjectCreditMovement $movement)
    {
        $campusIds = $this->authorizedCampuses($request, $studentClass);
        $data = $request->validate([
            'target_course_id' => ['required', 'integer', 'min:1'],
            'sessions' => ['required', 'integer', 'min:1', 'max:10000'],
            'reason' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'state_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $r = $movement->apply($studentClass, $data, (int) ($request->attributes->get('auth_user')->id ?? 0), $campusIds);

        return response()->json($r['body'], $r['status']);
    }

    public function reverse(Request $request, StudentClass $studentClass, int $transferId, SubjectCreditMovement $movement)
    {
        $campusIds = $this->authorizedCampuses($request, $studentClass);
        $data = $request->validate(['reason' => ['required', 'string', 'max:100']]);

        return response()->json($movement->reverse($studentClass, $transferId, $data['reason'], (int) ($request->attributes->get('auth_user')->id ?? 0), $campusIds));
    }

    private function authorizedCampuses(Request $request, StudentClass $course): ?array
    {
        $ids = $request->attributes->get('auth_role') === 'super_admin'
            ? null : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if ($ids !== null && !Student::where('id', $course->StudentID)->whereIn('CampusID', $ids)->exists()) {
            abort(403);
        }
        return $ids;
    }
}
