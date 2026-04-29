<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\ExceptionWorkflowCandidate;
use App\Models\Schedule;
use App\Services\ExceptionWorkflowCandidateGenerator;
use App\Services\ExceptionWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExceptionWorkflowController extends Controller
{
    public function index(Request $request, ExceptionWorkflowService $service)
    {
        $branchId = (int) $request->query('branch_id', 0);
        $campusIds = $this->authorizedCampusIds($request, $branchId);
        if ($campusIds === null) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = $service->queryForCampusIds($campusIds)
            ->with(['student', 'studentClass', 'classSession']);

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $items = $query->limit(50)->get()->map(fn (ExceptionWorkflow $workflow) => $this->serialize($workflow));

        return response()->json([
            'data' => $items,
            'meta' => ['count' => $items->count()],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $workflow = ExceptionWorkflow::with(['student', 'studentClass', 'classSession', 'candidates'])
            ->findOrFail($id);

        if (!$this->canAccessCampus($request, (int) $workflow->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->serialize($workflow, true),
        ]);
    }

    public function generateCandidates(Request $request, int $id, ExceptionWorkflowCandidateGenerator $generator)
    {
        $workflow = ExceptionWorkflow::with(['student', 'studentClass', 'classSession', 'candidates'])
            ->findOrFail($id);

        if (!$this->canAccessCampus($request, (int) $workflow->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $candidates = $generator->generate(
            $workflow,
            $data['start_date'],
            $data['end_date'],
            (int) ($data['limit'] ?? 10)
        );

        $workflow->refresh()->load(['student', 'studentClass', 'classSession', 'candidates']);

        return response()->json([
            'data' => [
                'workflow_id' => (int) $workflow->id,
                'generated_count' => count($candidates),
                'workflow' => $this->serialize($workflow),
                'candidates' => $workflow->candidates()
                    ->orderBy('rank')
                    ->get()
                    ->map(fn ($candidate) => $this->serializeCandidate($candidate))
                    ->values(),
            ],
        ]);
    }

    public function confirmCandidate(Request $request, int $id)
    {
        $workflow = ExceptionWorkflow::with(['student', 'studentClass', 'classSession'])
            ->findOrFail($id);

        if (!$this->canAccessCampus($request, (int) $workflow->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'candidate_id' => 'required|integer',
        ]);

        $candidate = ExceptionWorkflowCandidate::where('workflow_id', $workflow->id)
            ->where('id', (int) $data['candidate_id'])
            ->firstOrFail();

        if ($candidate->expires_at && Carbon::parse($candidate->expires_at)->isPast() && $workflow->status !== 'confirmed') {
            return response()->json(['message' => 'Candidate expired. Please regenerate candidates.'], 422);
        }

        $course = $workflow->studentClass;
        if (!$course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        if ($workflow->status !== 'confirmed' && !$this->candidateStillHasCapacity($workflow, $candidate)) {
            return response()->json(['message' => 'Candidate is no longer available. Please regenerate candidates.'], 409);
        }

        [$schedule, $classSession] = DB::transaction(function () use ($workflow, $candidate, $course) {
            $payload = $workflow->payload ?? [];
            $existingScheduleId = (int) ($payload['confirmed_schedule_id'] ?? 0);
            $existingSessionId = (int) ($payload['confirmed_class_session_id'] ?? 0);

            $schedule = $existingScheduleId > 0 ? Schedule::find($existingScheduleId) : null;
            $classSession = $existingSessionId > 0 ? ClassSession::find($existingSessionId) : null;
            $date = Carbon::parse($candidate->candidate_date)->toDateString();
            $startTime = $this->trimToHM($candidate->start_time);
            $endTime = $this->trimToHM($candidate->end_time);

            if (!$schedule) {
                $schedule = Schedule::firstOrCreate(
                    [
                        'student_course_id' => (int) $course->ID,
                        'schedule_date' => $date,
                        'start_time' => $startTime,
                        'status' => 'scheduled',
                        'type' => 'extra',
                    ],
                    [
                        'student_id' => (int) $workflow->student_id,
                        'teacher_id' => $candidate->teacher_id ? (int) $candidate->teacher_id : (int) ($course->TeacherID ?? 0),
                        'subject' => (string) ($course->SubjectID ?? ''),
                        'day_of_week' => Carbon::parse($date)->dayOfWeekIso,
                        'end_time' => $endTime,
                        'duration_hours' => $this->durationHours($startTime, $endTime),
                        'class_type' => (string) ($course->ClassType ?? 'one_on_one'),
                        'deduction' => 1,
                        'branch_id' => (int) $workflow->campus_id,
                        'original_schedule_id' => null,
                    ]
                );
            }

            if (!$classSession) {
                $classSession = ClassSession::firstOrCreate(
                    [
                        'StudentClassID' => (int) $course->ID,
                        'SessionDate' => $date,
                        'StartTime' => $startTime,
                    ],
                    [
                        'EndTime' => $endTime,
                        'Status' => 'scheduled',
                        'Note' => 'exception_workflow:' . $workflow->id,
                        'IsContractException' => true,
                        'SubjectID' => $course->SubjectID ?? null,
                    ]
                );
            }

            $workflow->status = 'confirmed';
            $workflow->closed_at = now();
            $workflow->closed_reason = 'candidate_confirmed';
            $workflow->payload = array_merge($payload, [
                'confirmed_candidate_id' => (int) $candidate->id,
                'confirmed_schedule_id' => (int) $schedule->id,
                'confirmed_class_session_id' => (int) $classSession->id,
                'confirmed_at' => now()->toIso8601String(),
            ]);
            $workflow->save();

            return [$schedule, $classSession];
        });

        $workflow->refresh()->load(['student', 'studentClass', 'classSession', 'candidates']);

        return response()->json([
            'data' => [
                'workflow' => $this->serialize($workflow),
                'candidate' => $this->serializeCandidate($candidate),
                'schedule' => $this->serializeSchedule($schedule),
                'class_session' => $this->serializeClassSession($classSession),
            ],
        ]);
    }

    private function authorizedCampusIds(Request $request, int $branchId): ?array
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return $branchId > 0 ? [$branchId] : [];
        }

        $allowed = array_values(array_unique(array_map('intval', $request->attributes->get('auth_campus_ids', []))));
        if ($branchId > 0) {
            return in_array($branchId, $allowed, true) ? [$branchId] : null;
        }

        return $allowed;
    }

    private function canAccessCampus(Request $request, int $campusId): bool
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return true;
        }

        $allowed = array_map('intval', $request->attributes->get('auth_campus_ids', []));
        return in_array($campusId, $allowed, true);
    }

    private function serialize(ExceptionWorkflow $workflow, bool $withCandidates = false): array
    {
        $session = $workflow->classSession;
        $data = [
            'id' => (int) $workflow->id,
            'type' => $workflow->type,
            'status' => $workflow->status,
            'severity' => $workflow->severity,
            'campus_id' => (int) $workflow->campus_id,
            'due_at' => optional($workflow->due_at)->toIso8601String(),
            'created_at' => optional($workflow->created_at)->toIso8601String(),
            'student' => $workflow->student ? [
                'id' => (int) $workflow->student->id,
                'name' => $workflow->student->name,
            ] : null,
            'student_class' => $workflow->studentClass ? [
                'id' => (int) $workflow->studentClass->ID,
                'subject_id' => (int) ($workflow->studentClass->SubjectID ?? 0),
                'teacher_id' => (int) ($workflow->studentClass->TeacherID ?? 0),
            ] : null,
            'class_session' => $session ? [
                'id' => (int) $session->id,
                'date' => (string) $session->SessionDate,
                'start_time' => $this->trimToHM($session->StartTime),
                'end_time' => $this->trimToHM($session->EndTime),
                'status' => $session->Status,
            ] : null,
            'payload' => $workflow->payload ?? [],
        ];

        if ($withCandidates) {
            $data['candidates'] = $workflow->candidates
                ->sortBy('rank')
                ->values()
                ->map(fn ($candidate) => $this->serializeCandidate($candidate))
                ->all();
        }

        return $data;
    }

    private function serializeCandidate($candidate): array
    {
        return [
            'id' => (int) $candidate->id,
            'workflow_id' => (int) $candidate->workflow_id,
            'rank' => (int) $candidate->rank,
            'candidate_date' => optional($candidate->candidate_date)->toDateString(),
            'start_time' => $this->trimToHM($candidate->start_time),
            'end_time' => $this->trimToHM($candidate->end_time),
            'teacher_id' => $candidate->teacher_id ? (int) $candidate->teacher_id : null,
            'room_id' => $candidate->room_id ? (int) $candidate->room_id : null,
            'status' => $candidate->status,
            'score' => (int) $candidate->score,
            'reasons' => $candidate->reasons ?? [],
            'expires_at' => optional($candidate->expires_at)->toIso8601String(),
        ];
    }

    private function serializeSchedule(Schedule $schedule): array
    {
        return [
            'id' => (int) $schedule->id,
            'status' => $schedule->status,
            'type' => $schedule->type,
            'schedule_date' => (string) $schedule->schedule_date,
            'start_time' => $this->trimToHM($schedule->start_time),
            'end_time' => $this->trimToHM($schedule->end_time),
        ];
    }

    private function serializeClassSession(ClassSession $session): array
    {
        return [
            'id' => (int) $session->id,
            'status' => $session->Status,
            'session_date' => (string) $session->SessionDate,
            'start_time' => $this->trimToHM($session->StartTime),
            'end_time' => $this->trimToHM($session->EndTime),
        ];
    }

    private function candidateStillHasCapacity(ExceptionWorkflow $workflow, ExceptionWorkflowCandidate $candidate): bool
    {
        $workflow->loadMissing('studentClass');
        $course = $workflow->studentClass;
        if (!$course) {
            return false;
        }

        $capacity = match ((string) ($course->ClassType ?? 'one_on_one')) {
            'one_on_two' => 2,
            'one_on_three' => 3,
            'tutoring' => 4,
            default => 1,
        };
        $count = ClassSession::query()
            ->join('StudentClass as sc', 'sc.ID', '=', 'ClassSession.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.TeacherID', (int) ($candidate->teacher_id ?? $course->TeacherID ?? 0))
            ->where('st.CampusID', (int) $workflow->campus_id)
            ->whereDate('ClassSession.SessionDate', Carbon::parse($candidate->candidate_date)->toDateString())
            ->whereNotIn('ClassSession.Status', ['cancelled', 'leave', 'leave_requested'])
            ->where('ClassSession.id', '!=', (int) $workflow->class_session_id)
            ->where(function ($query) use ($candidate) {
                $start = $this->trimToHM($candidate->start_time);
                $end = $this->trimToHM($candidate->end_time);
                $query->whereRaw('SUBSTRING(ClassSession.StartTime,1,5) < ?', [$end])
                    ->whereRaw('SUBSTRING(ClassSession.EndTime,1,5) > ?', [$start]);
            })
            ->count();

        return $count < $capacity;
    }

    private function durationHours(string $startTime, string $endTime): float
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        if (!$end->gt($start)) {
            return 0.5;
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    private function trimToHM(?string $time): string
    {
        if (!$time) {
            return '';
        }

        return preg_replace('/^(\d{1,2}:\d{2}).*$/', '$1', $time);
    }
}
