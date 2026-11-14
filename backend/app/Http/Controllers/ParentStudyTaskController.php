<?php

namespace App\Http\Controllers;

use App\Models\Scopes\OperationalTenantScope;
use App\Models\Student;
use App\Models\StudentStudyTask;
use App\Services\ParentBinding\ParentGuardianAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 家長端：孩子這週的讀書任務 (#5269 step 4). Read only.
 *
 * Scoping is the parent portal's own: the bearer token is a ParentSession looked up by ParentGuardianAccessService::liveSession()
 * (the one parent-token lookup every parent endpoint uses, which also expires a session whose guardian link was revoked), and the
 * child is that session's student. No student id is ever read from the request, so a parent cannot name another child. Switching
 * to a sibling issues a new session through parent/switch-student, so the same rule covers every guardian of the same child.
 *
 * Exposed per task, and nothing else: id, title, kind, due_date, minutes, done_at (a date). `note` (teacher's free text, may be an
 * internal reminder), `plan_unit_id`, `created_by`, `done_by`, `campus_id` and timestamps are staff-only and never leave here.
 */
class ParentStudyTaskController extends Controller
{
    /** Tasks of the session's child whose due date is in the Mon–Sun week of `week_start` (default: this week, Asia/Taipei). */
    public function week(Request $request): JsonResponse
    {
        $session = app(ParentGuardianAccessService::class)->liveSession($request->bearerToken());
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $data = $request->validate(['week_start' => ['sometimes', 'date_format:Y-m-d']]);

        $student = Student::withoutGlobalScope(OperationalTenantScope::class)->find((int) $session->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $anchor = Carbon::createFromFormat('Y-m-d', $data['week_start'] ?? Carbon::now('Asia/Taipei')->toDateString(), 'Asia/Taipei');
        $start = $anchor->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $end = Carbon::parse($start)->addDays(6)->toDateString();

        $tasks = StudentStudyTask::query()
            ->where('campus_id', $student->CampusID)->where('student_id', $student->id)
            ->whereBetween('due_date', [$start, $end])
            ->orderBy('due_date')->orderBy('id')->limit(100)->get();

        return response()->json([
            'week_start' => $start,
            'week_end' => $end,
            'data' => $tasks->map(fn (StudentStudyTask $t) => [
                'id' => (int) $t->id,
                'title' => (string) $t->title,
                'kind' => (string) $t->kind,
                'due_date' => $t->due_date->toDateString(),
                'minutes' => $t->minutes === null ? null : (int) $t->minutes,
                'done_at' => $t->done_at?->timezone('Asia/Taipei')->toDateString(),
            ])->values()->all(),
        ]);
    }
}
