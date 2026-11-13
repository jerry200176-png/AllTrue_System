<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesLearningPlanAccess;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLearningPlan;
use App\Models\StudentPlanUnit;
use App\Support\LearningRecordMutableOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 「這堂上完的單元」(#4970 step 5): tick plan units done from the lesson evaluation, no second data entry.
 * It sits beside the evaluation save (LearningRecordController::store/update, untouched): the form calls it after
 * the evaluation was saved, so deduction, approval and the teacher lock keep their own paths.
 * Read: whoever may see the course's plan. Write: the evaluation's editor (same ownership and lock rule as
 * LearningRecordController::update) AND the plan write scope (director, or the course's own teacher).
 */
class StudentPlanUnitLessonController extends Controller
{
    use ScopesLearningPlanAccess;

    private const SHOWN = 8;

    /** Not-yet-done units of the course's active plan, those planned around the lesson date first. */
    public function candidates(Request $request): JsonResponse
    {
        $session = ClassSession::query()->find((int) $request->query('class_session_id'));
        $course = $session ? StudentClass::query()->where('ID', $session->StudentClassID)->first() : null;
        $student = $course ? Student::query()->find($course->StudentID) : null;
        if ($student === null || !$this->campusAllowed($request, (int) $student->CampusID)) {
            return $session === null ? response()->json(['message' => '找不到這堂課'], 404) : $this->forbidden();
        }
        $teacherId = $this->teacherId($request);
        if ($teacherId !== null && !StudentClass::query()->where('ID', $course->ID)->visibleToTeacher($teacherId)->exists()) {
            return $this->forbidden();
        }
        $plan = $this->activePlan((int) $course->ID);
        $empty = ['plan' => null, 'can_tick' => false, 'lesson_date' => null, 'total' => 0, 'units' => []];
        if ($plan === null) {
            return response()->json(['data' => $empty]);
        }
        $lesson = $this->day(substr((string) $session->SessionDate, 0, 10));
        // Open units, plus the ones already ticked from THIS lesson (so the form can show and untick them).
        $units = $plan->units()->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_session_id', $session->id))
            ->orderBy('sort_order')->get();
        $ranked = $units->map(fn (StudentPlanUnit $u) => [$u, $this->distance($u, $lesson)])
            ->sort(fn ($a, $b) => [$a[1], $a[0]->sort_order] <=> [$b[1], $b[0]->sort_order])->values();
        $showAll = $request->boolean('all');
        $shown = $ranked->filter(fn ($r, $i) => $showAll || $i < self::SHOWN || $r[0]->completed_session_id !== null)->values();

        return response()->json(['data' => [
            'plan' => ['id' => $plan->id, 'title' => $plan->title],
            'can_tick' => $this->courseWritable($request, (int) $course->ID),
            'lesson_date' => $lesson->toDateString(), 'total' => $units->count(),
            'units' => $shown->map(fn ($r) => [
                'id' => $r[0]->id, 'title' => $r[0]->title, 'planned_start' => $r[0]->planned_start->toDateString(),
                'planned_end' => $r[0]->planned_end->toDateString(), 'behind' => $r[0]->planned_end->lt($lesson),
                'checked' => $r[0]->completed_session_id !== null,
            ])->all(),
        ]]);
    }

    /**
     * Set exactly which units this evaluation completed. Ticks open units (completed_at = lesson date), clears
     * only the ones ticked from this same evaluation, ignores units completed elsewhere. Idempotent.
     */
    public function sync(Request $request, LearningRecord $learningRecord): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $course = StudentClass::query()->where('ID', $learningRecord->StudentClassID)->first();
        $student = $course ? Student::query()->find($course->StudentID) : null;
        if ($student === null || !$this->campusAllowed($request, (int) $student->CampusID)) {
            return $this->forbidden();
        }
        if ($role === 'teacher' && !LearningRecordMutableOwnership::isActionableOwner($learningRecord, (int) ($this->teacherId($request) ?? 0))) {
            return $this->forbidden();
        }
        if (!$this->courseWritable($request, (int) $course->ID)) {
            return $this->forbidden();
        }
        $data = $request->validate(['unit_ids' => ['present', 'array', 'max:200'], 'unit_ids.*' => ['integer', 'min:1']]);

        $error = DB::transaction(function () use ($request, $role, $learningRecord, $course, $data) {
            // Same lock order as LearningRecordController::update (record first), then the course and plan rows.
            $lr = LearningRecord::query()->whereKey($learningRecord->getKey())->lockForUpdate()->firstOrFail();
            StudentClass::query()->where('ID', $course->ID)->lockForUpdate()->first();
            // The existing lock rule: a teacher may only touch an evaluation that is still editable for them.
            if (!in_array($role, ['director', 'super_admin', 'admin'], true) && !in_array($lr->Status, ['pending', 'rejected', 'changes_requested'], true)) {
                return ['已核准的評量不能再更動單元，請聯絡主任', 409];
            }
            if ($lr->VoidedAt !== null || (int) $lr->ClassSessionID <= 0) {
                return ['這份評量沒有對應的有效課堂', 422];
            }
            $session = ClassSession::query()->find((int) $lr->ClassSessionID);
            $date = $session ? substr((string) $session->SessionDate, 0, 10) : '';
            if ($session === null || $date === '' || $date > now(config('app.timezone', 'Asia/Taipei'))->toDateString()) {
                return ['課程尚未開始，請於上課時間後再勾選單元', 422];
            }
            $plan = StudentLearningPlan::query()->where('student_class_id', $course->ID)->where('status', 'active')->lockForUpdate()->first();
            $want = array_values(array_unique(array_map('intval', $data['unit_ids'])));
            if ($plan === null) {
                return $want === [] ? null : ['這門課沒有使用中的學習計畫', 422];
            }
            $units = $plan->units()->get()->keyBy('id');
            if (array_diff($want, $units->keys()->all()) !== []) {
                return ['有單元不屬於這門課的學習計畫', 422];
            }
            foreach ($units as $u) {
                $ticked = in_array($u->id, $want, true);
                if ($ticked && $u->completed_at === null) {
                    $u->update(['completed_at' => $this->day($date), 'completed_by' => $request->attributes->get('auth_user')?->id,
                        'completed_session_id' => $session->id, 'completed_record_id' => $lr->id]);
                } elseif (!$ticked && $u->completed_record_id !== null && (int) $u->completed_record_id === (int) $lr->id) {
                    $u->update(['completed_at' => null, 'completed_by' => null, 'completed_session_id' => null, 'completed_record_id' => null]);
                }
            }

            return null;
        });
        if ($error !== null) {
            return response()->json(['message' => $error[0]], $error[1]);
        }
        $done = StudentPlanUnit::query()->where('completed_record_id', $learningRecord->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return response()->json(['data' => ['unit_ids' => $done]]);
    }

    private function activePlan(int $courseId): ?StudentLearningPlan
    {
        return StudentLearningPlan::query()->where('student_class_id', $courseId)->where('status', 'active')->orderByDesc('id')->first();
    }

    /** 0 when the lesson falls inside the unit's planned range, else days to the nearest edge. */
    private function distance(StudentPlanUnit $u, Carbon $lesson): int
    {
        if ($lesson->lt($u->planned_start)) {
            return (int) $lesson->diffInDays($u->planned_start);
        }

        return $lesson->gt($u->planned_end) ? (int) $u->planned_end->diffInDays($lesson) : 0;
    }

    private function day(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
    }
}
