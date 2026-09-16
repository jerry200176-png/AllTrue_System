<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentCampusPresence;
use App\Services\StudentCampusPresenceService;
use Illuminate\Http\Request;

/**
 * Read APIs for campus presence evidence (#2809 RFID-1).
 * Does not mark attendance or deduct sessions.
 */
class CampusPresenceController extends Controller
{
    public function __construct(
        private readonly StudentCampusPresenceService $presenceService,
    ) {
    }

    /**
     * GET /api/v1/campus-presence/open?campus_id=
     * Who is currently on campus (open presence).
     */
    public function open(Request $request)
    {
        $data = $request->validate([
            'campus_id' => 'required|integer',
        ]);

        $rows = $this->presenceService->openForCampus((int) $data['campus_id']);

        return response()->json([
            'ok' => true,
            'data' => $rows->map(fn (StudentCampusPresence $p) => $this->serializePresence($p))->values(),
        ]);
    }

    /**
     * GET /api/v1/students/{student}/campus-presence/today
     */
    public function studentToday(Request $request, Student $student)
    {
        $campusId = (int) ($request->query('campus_id') ?: $student->CampusID);
        $today = now()->toDateString();

        $rows = StudentCampusPresence::query()
            ->where('StudentID', $student->id)
            ->where('CampusID', $campusId)
            ->whereDate('ArrivedAt', $today)
            ->whereNull('VoidedAt')
            ->orderBy('ArrivedAt')
            ->get();

        $open = $rows->first(fn (StudentCampusPresence $p) => $p->isOpen());
        $payload = $open
            ? $this->presenceService->candidatesForStudent($student, now())
            : ['candidates' => [], 'ambiguous' => false, 'exception' => null];

        return response()->json([
            'ok' => true,
            'student_id' => $student->id,
            'campus_id' => $campusId,
            'presence' => $rows->map(fn (StudentCampusPresence $p) => $this->serializePresence($p))->values(),
            'on_campus' => $open !== null,
            'candidates' => $payload['candidates'],
            'ambiguous' => $payload['ambiguous'],
            'exception' => $payload['exception'],
        ]);
    }

    /**
     * GET /api/v1/students/{student}/campus-presence/candidates
     */
    public function candidates(Request $request, Student $student)
    {
        $at = $request->query('at') ? \Carbon\Carbon::parse((string) $request->query('at')) : now();
        $payload = $this->presenceService->candidatesForStudent($student, $at);

        return response()->json([
            'ok' => true,
            'student_id' => $student->id,
            'at' => $at->toIso8601String(),
            'candidates' => $payload['candidates'],
            'ambiguous' => $payload['ambiguous'],
            'exception' => $payload['exception'],
        ]);
    }

    private function serializePresence(StudentCampusPresence $p): array
    {
        return [
            'id' => $p->id,
            'campus_id' => $p->CampusID,
            'student_id' => $p->StudentID,
            'student_name' => $p->relationLoaded('student') ? ($p->student->name ?? null) : null,
            'source' => $p->Source,
            'device_id' => $p->DeviceID,
            'arrived_at' => optional($p->ArrivedAt)?->toDateTimeString(),
            'departed_at' => optional($p->DepartedAt)?->toDateTimeString(),
            'status' => $p->Status,
            'close_reason' => $p->CloseReason,
            'on_campus' => $p->isOpen(),
        ];
    }
}
