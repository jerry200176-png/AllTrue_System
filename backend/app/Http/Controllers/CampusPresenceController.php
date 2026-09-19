<?php
namespace App\Http\Controllers;
use App\Models\Student;
use App\Models\StudentCampusPresence;
use App\Services\StudentCampusPresenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
class CampusPresenceController extends Controller
{
    public function __construct(private readonly StudentCampusPresenceService $presenceService) {}
    public function open(Request $request)
    {
        $data = $request->validate(['campus_id' => 'required|integer|min:1']);
        $campusId = (int) $data['campus_id'];
        $this->assertCampusAuthorized($request, $campusId);
        $rows = $this->presenceService->openForCampus($campusId);
        return response()->json(['ok' => true, 'data' => $rows->map(fn (StudentCampusPresence $p) => $this->serialize($p))->values()]);
    }
    public function studentToday(Request $request, int $studentId)
    {
        $student = $this->scopedStudent($request, $studentId);
        $data = $request->validate(['campus_id' => 'sometimes|integer|min:1']);
        $campusId = (int) ($data['campus_id'] ?? $student->CampusID);
        abort_unless($campusId === (int) $student->CampusID, 404);
        $this->assertCampusAuthorized($request, $campusId);
        $rows = StudentCampusPresence::query()
            ->where('StudentID', (int) $student->getKey())
            ->where('CampusID', $campusId)
            ->where(function ($q) { $q->whereDate('ArrivedAt', now()->toDateString())->orWhere(function ($q) { $q->where('Status', StudentCampusPresence::STATUS_OPEN)->whereNull('DepartedAt'); }); })
            ->whereNull('VoidedAt')
            ->orderBy('ArrivedAt')
            ->get();
        $hasPresence = $this->presenceService->hasPresenceAt($student, now());
        $payload = $hasPresence
            ? $this->presenceService->candidatesForStudent($student, now())
            : ['candidates' => [], 'ambiguous' => false, 'exception' => null];
        return response()->json(['ok' => true, 'student_id' => (int) $student->getKey(), 'campus_id' => $campusId, 'presence' => $rows->map(fn (StudentCampusPresence $p) => $this->serialize($p))->values(), 'on_campus' => $hasPresence, 'candidates' => $payload['candidates'], 'ambiguous' => $payload['ambiguous'], 'exception' => $payload['exception']]);
    }
    public function candidates(Request $request, int $studentId)
    {
        $student = $this->scopedStudent($request, $studentId);
        $data = $request->validate([
            'campus_id' => 'sometimes|integer|min:1',
            'at' => 'sometimes|date',
        ]);
        $campusId = (int) ($data['campus_id'] ?? $student->CampusID);
        abort_unless($campusId === (int) $student->CampusID, 404);
        $this->assertCampusAuthorized($request, $campusId);
        $at = isset($data['at']) ? Carbon::parse((string) $data['at']) : now();
        $payload = $this->presenceService->candidatesForStudent($student, $at);
        return response()->json(['ok' => true, 'student_id' => (int) $student->getKey(), 'at' => $at->toIso8601String(), ...$payload]);
    }
    private function serialize(StudentCampusPresence $p): array
    {
        return ['id' => $p->id, 'campus_id' => $p->CampusID, 'student_id' => $p->StudentID, 'student_name' => $p->relationLoaded('student') ? ($p->student->name ?? null) : null, 'source' => $p->Source, 'device_id' => $p->DeviceID, 'arrived_at' => optional($p->ArrivedAt)?->toDateTimeString(), 'departed_at' => optional($p->DepartedAt)?->toDateTimeString(), 'status' => $p->Status, 'close_reason' => $p->CloseReason, 'on_campus' => $p->isOpen()];
    }
    private function assertCampusAuthorized(Request $request, int $campusId): void
    {
        if ($request->attributes->get('auth_role') === 'super_admin') return;
        abort_unless(in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true), 403);
    }
    private function scopedStudent(Request $request, int $studentId): Student
    {
        $query = Student::query()->whereKey($studentId);
        if ($request->attributes->get('auth_role') !== 'super_admin') {
            $query->whereIn('CampusID', array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])));
        }
        $student = $query->first();
        if (!$student) {
            abort(404);
        }
        return $student;
    }
}
