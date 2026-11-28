<?php

namespace App\Http\Controllers;

use App\Models\ExamRound;
use App\Models\ExamScore;
use App\Models\Scopes\OperationalTenantScope;
use App\Models\Student;
use App\Services\ParentBinding\ParentGuardianAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 家長端：孩子的段考分數和老師的話. Read only.
 *
 * Scoping follows ParentStudyTaskController: the bearer token is a live ParentSession and the child is that session's student; no
 * student id is read from the request. Rounds: `closed` only (draft / published / void never), on the child's current campus,
 * and only those where the child has a score row (a score row is the proof they sat it; grade_ids is not re-checked, so a round
 * from before a grade promotion still shows). Latest 3 by closing time.
 *
 * Exposed per round: id, title, exam_date; per subject the child has a score for: name, max_score, score, is_absent,
 * parent_comment. `notes` (internal), user ids, other students and timestamps never leave here.
 */
class ParentExamResultController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $session = app(ParentGuardianAccessService::class)->liveSession($request->bearerToken());
        if (!$session) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $student = Student::withoutGlobalScope(OperationalTenantScope::class)->find((int) $session->StudentID);
        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        $rounds = ExamRound::query()
            ->where('campus_id', (int) $student->CampusID)->where('status', 'closed')
            ->whereIn('id', ExamScore::query()->select('exam_round_id')->where('student_id', $student->id))
            ->orderByDesc('closed_at')->orderByDesc('id')->limit(3)
            ->with('subjects')->get();
        $scores = ExamScore::query()->where('student_id', $student->id)
            ->whereIn('exam_round_id', $rounds->pluck('id')->all() ?: [0])
            ->get()->keyBy(fn (ExamScore $s) => $s->exam_round_id . ':' . $s->exam_round_subject_id);

        return response()->json(['data' => $rounds->map(fn (ExamRound $r) => [
            'id' => (int) $r->id,
            'title' => (string) $r->title,
            'exam_date' => $r->exam_date?->toDateString(),
            'subjects' => $r->subjects->filter(fn ($sub) => $scores->has($r->id . ':' . $sub->id))->map(function ($sub) use ($r, $scores) {
                $s = $scores->get($r->id . ':' . $sub->id);
                return [
                    'name' => (string) $sub->name,
                    'max_score' => (float) $sub->max_score,
                    'score' => $s->is_absent || $s->score === null ? null : (float) $s->score,
                    'is_absent' => (bool) $s->is_absent,
                    'parent_comment' => $s->parent_comment,
                ];
            })->values()->all(),
        ])->values()->all()]);
    }
}
