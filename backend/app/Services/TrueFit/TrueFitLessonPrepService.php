<?php

namespace App\Services\TrueFit;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\TrueFitLessonPrep;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Create/read TrueFit lesson preps with fixture Teacher Briefs.
 * Never writes LearningRecord, attendance, enrollment, or billing.
 */
final class TrueFitLessonPrepService
{
    public function __construct(
        private TrueFitMaterialCatalog $catalog,
        private FixtureTeacherBriefProvider $briefProvider,
    ) {
    }

    /**
     * @return list<array{key:string,subject_hint:string,title:string,unit_label:string,summary:string}>
     */
    public function listMaterialUnits(?string $subjectHint = null): array
    {
        return $this->catalog->listUnits($subjectHint);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrepForTeacher(Request $request, int $teacherId): array
    {
        $query = TrueFitLessonPrep::query()->where('teacher_user_id', $teacherId);
        $this->applySessionFilters($query, $request);

        $prep = $query->orderByDesc('id')->first();
        if (!$prep) {
            return ['data' => null];
        }

        $this->assertCampusAccess($request, (int) $prep->campus_id);

        return ['data' => $this->serialize($prep)];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForTeacher(Request $request, int $teacherId): array
    {
        $materialKey = trim((string) $request->input('material_unit_key', ''));
        $material = $this->catalog->find($materialKey);
        if ($material === null) {
            throw ValidationException::withMessages([
                'material_unit_key' => ['Unknown material unit'],
            ]);
        }

        $session = $this->resolveSessionContext($request, $teacherId);
        $this->assertCampusAccess($request, $session['campus_id']);

        $brief = $this->briefProvider->generate($material, $session['subject_name']);

        $prep = TrueFitLessonPrep::query()->updateOrCreate(
            [
                'teacher_user_id' => $teacherId,
                'class_session_id' => $session['class_session_id'],
                'student_class_id' => $session['student_class_id'],
                'session_date' => $session['session_date'],
                'start_time' => $session['start_time'],
            ],
            [
                'campus_id' => $session['campus_id'],
                'material_unit_key' => $material['key'],
                'status' => 'ready',
                'brief_provider' => 'fixture',
                'brief_schema_version' => TrueFitTeacherBriefContract::SCHEMA_VERSION,
                'brief_json' => $brief,
                'generated_at' => Carbon::now(config('app.timezone', 'Asia/Taipei')),
            ]
        );

        return ['data' => $this->serialize($prep)];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\TrueFitLessonPrep> $query
     */
    private function applySessionFilters($query, Request $request): void
    {
        $classSessionId = (int) $request->input('class_session_id', 0);
        if ($classSessionId > 0) {
            $query->where('class_session_id', $classSessionId);

            return;
        }

        $studentClassId = (int) $request->input('student_class_id', 0);
        $sessionDate = substr((string) $request->input('session_date', ''), 0, 10);
        $startTime = substr((string) $request->input('start_time', ''), 0, 5);
        if ($studentClassId <= 0 || $sessionDate === '' || $startTime === '') {
            throw ValidationException::withMessages([
                'session' => ['Provide class_session_id or student_class_id + session_date + start_time'],
            ]);
        }

        $query->where('class_session_id', 0)
            ->where('student_class_id', $studentClassId)
            ->whereDate('session_date', $sessionDate)
            ->where('start_time', $startTime);
    }

    /**
     * @return array{
     *   campus_id:int,
     *   class_session_id:int,
     *   student_class_id:int,
     *   session_date:string,
     *   start_time:string,
     *   subject_name:string
     * }
     */
    private function resolveSessionContext(Request $request, int $teacherId): array
    {
        $classSessionId = (int) $request->input('class_session_id', 0);
        if ($classSessionId > 0) {
            $row = ClassSession::query()->with(['studentClass.subjectRecord', 'studentClass.room', 'studentClass.student'])
                ->where('id', $classSessionId)
                ->first();
            if (!$row) {
                throw ValidationException::withMessages(['class_session_id' => ['Session not found']]);
            }

            $course = $row->studentClass;
            if (!$course || (int) ($course->TeacherID ?? 0) !== $teacherId) {
                throw ValidationException::withMessages(['class_session_id' => ['Forbidden']]);
            }

            $campusId = $this->resolveCampusIdFromCourse($course);
            $subjectName = (string) ($course->subjectRecord?->Name
                ?? $course->subjectRecord?->name
                ?? $request->input('subject_name', '')
                ?? '');

            return [
                'campus_id' => $campusId,
                'class_session_id' => (int) $row->id,
                'student_class_id' => (int) $row->StudentClassID,
                'session_date' => substr((string) $row->SessionDate, 0, 10),
                'start_time' => substr((string) $row->StartTime, 0, 5),
                'subject_name' => $subjectName,
            ];
        }

        $studentClassId = (int) $request->input('student_class_id', 0);
        $sessionDate = substr((string) $request->input('session_date', ''), 0, 10);
        $startTime = substr((string) $request->input('start_time', ''), 0, 5);
        if ($studentClassId <= 0 || $sessionDate === '' || $startTime === '') {
            throw ValidationException::withMessages([
                'session' => ['Provide class_session_id or student_class_id + session_date + start_time'],
            ]);
        }

        $course = StudentClass::query()->with(['subjectRecord', 'room', 'student'])->find($studentClassId);
        if (!$course || (int) ($course->TeacherID ?? 0) !== $teacherId) {
            throw ValidationException::withMessages(['student_class_id' => ['Forbidden']]);
        }

        $campusId = $this->resolveCampusIdFromCourse($course);
        $subjectName = (string) ($course->subjectRecord?->Name
            ?? $course->subjectRecord?->name
            ?? $request->input('subject_name', '')
            ?? '');

        return [
            'campus_id' => $campusId,
            'class_session_id' => 0,
            'student_class_id' => $studentClassId,
            'session_date' => $sessionDate,
            'start_time' => $startTime,
            'subject_name' => $subjectName,
        ];
    }

    private function resolveCampusIdFromCourse(?StudentClass $course): int
    {
        if (!$course) {
            throw new InvalidArgumentException('Missing course');
        }
        $fromRoom = (int) ($course->room?->campus_id ?? 0);
        if ($fromRoom > 0) {
            return $fromRoom;
        }
        $fromStudent = (int) ($course->student?->CampusID ?? 0);
        if ($fromStudent > 0) {
            return $fromStudent;
        }
        // Legacy StudentClass.by1 often stores campus id when room is unset.
        $fromBy1 = (int) ($course->by1 ?? 0);
        if ($fromBy1 > 0) {
            return $fromBy1;
        }

        throw ValidationException::withMessages(['campus_id' => ['Unable to resolve campus']]);
    }

    private function assertCampusAccess(Request $request, int $campusId): void
    {
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array($campusId, $campusIds, true)) {
            throw ValidationException::withMessages(['campus_id' => ['Forbidden: campus not accessible']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TrueFitLessonPrep $prep): array
    {
        return [
            'id' => (int) $prep->id,
            'campus_id' => (int) $prep->campus_id,
            'teacher_user_id' => (int) $prep->teacher_user_id,
            'class_session_id' => ((int) $prep->class_session_id) > 0 ? (int) $prep->class_session_id : null,
            'student_class_id' => (int) $prep->student_class_id,
            'session_date' => substr((string) $prep->session_date, 0, 10),
            'start_time' => substr((string) $prep->start_time, 0, 5),
            'material_unit_key' => (string) $prep->material_unit_key,
            'status' => (string) $prep->status,
            'brief_provider' => (string) $prep->brief_provider,
            'brief_schema_version' => (int) $prep->brief_schema_version,
            'brief' => $prep->brief_json,
            'generated_at' => optional($prep->generated_at)?->toIso8601String(),
        ];
    }
}
