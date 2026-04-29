<?php

namespace App\Http\Controllers;

use App\Models\ExceptionWorkflow;
use App\Services\ExceptionWorkflowService;
use Illuminate\Http\Request;

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
            $data['candidates'] = $workflow->candidates->values();
        }

        return $data;
    }

    private function trimToHM(?string $time): string
    {
        if (!$time) {
            return '';
        }

        return preg_replace('/^(\d{1,2}:\d{2}).*$/', '$1', $time);
    }
}
