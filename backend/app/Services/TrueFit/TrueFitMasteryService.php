<?php

namespace App\Services\TrueFit;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\TrueFitMasteryEvidence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Create/read TrueFit teacher mastery evidence (structured JSON).
 * Never writes LearningRecord, attendance, enrollment, or billing.
 */
final class TrueFitMasteryService
{
    public function __construct(
        private TrueFitMasteryContract $contract,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getForTeacher(Request $request, int $teacherId): array
    {
        $query = TrueFitMasteryEvidence::query()->where('teacher_user_id', $teacherId);
        $this->applySessionFilters($query, $request);

        /** @var TrueFitMasteryEvidence|null $row */
        $row = $query->orderByDesc('id')->first();
        if (!$row instanceof TrueFitMasteryEvidence) {
            return ['data' => null];
        }

        $this->assertCampusAccess($request, (int) $row->campus_id);

        return ['data' => $this->serialize($row)];
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertForTeacher(Request $request, int $teacherId): array
    {
        $session = $this->resolveSessionContext($request, $teacherId);
        $this->assertCampusAccess($request, $session['campus_id']);

        $payload = $request->input('mastery');
        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                'mastery' => ['mastery object is required'],
            ]);
        }

        $payload['schema_version'] = TrueFitMasteryContract::SCHEMA_VERSION;
        $payload['session_ref'] = $this->sessionRefFromContext($session);
        if (!array_key_exists('source_remediation_id', $payload)) {
            $payload['source_remediation_id'] = null;
        }
        if (!isset($payload['checked_at']) || trim((string) $payload['checked_at']) === '') {
            $payload['checked_at'] = Carbon::now(config('app.timezone', 'Asia/Taipei'))->toIso8601String();
        }
        if (!isset($payload['teacher_decision']) || trim((string) $payload['teacher_decision']) === '') {
            $payload['teacher_decision'] = 'pending';
        }
        if (!isset($payload['outcome']) || trim((string) $payload['outcome']) === '') {
            $payload['outcome'] = 'not_checked';
        }
        if (!isset($payload['next_review_window']) || trim((string) $payload['next_review_window']) === '') {
            $payload['next_review_window'] = 'none';
        }
        foreach (['student_response_summary', 'evidence_notes'] as $optionalStr) {
            if (!array_key_exists($optionalStr, $payload) || $payload[$optionalStr] === null) {
                $payload[$optionalStr] = '';
            } else {
                $payload[$optionalStr] = (string) $payload[$optionalStr];
            }
        }
        if ($payload['source_remediation_id'] !== null) {
            $payload['source_remediation_id'] = (int) $payload['source_remediation_id'];
        }

        try {
            $payload = $this->contract->assertValid($payload);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'mastery' => [$e->getMessage()],
            ]);
        }

        /** @var TrueFitMasteryEvidence $row */
        $row = TrueFitMasteryEvidence::query()->updateOrCreate(
            [
                'teacher_user_id' => $teacherId,
                'class_session_id' => $session['class_session_id'],
                'student_class_id' => $session['student_class_id'],
                'session_date' => $session['session_date'],
                'start_time' => $session['start_time'],
            ],
            [
                'campus_id' => $session['campus_id'],
                'status' => 'saved',
                'mastery_schema_version' => TrueFitMasteryContract::SCHEMA_VERSION,
                'outcome' => (string) $payload['outcome'],
                'next_review_window' => (string) $payload['next_review_window'],
                'teacher_decision' => (string) $payload['teacher_decision'],
                'source_remediation_id' => $payload['source_remediation_id'] !== null ? (int) $payload['source_remediation_id'] : null,
                'mastery_json' => $payload,
                'checked_at' => Carbon::parse((string) $payload['checked_at']),
            ]
        );

        return ['data' => $this->serialize($row)];
    }

    /**
     * @param Builder $query
     */
    private function applySessionFilters(Builder $query, Request $request): void
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
     *   start_time:string
     * }
     */
    private function resolveSessionContext(Request $request, int $teacherId): array
    {
        $classSessionId = (int) $request->input('class_session_id', 0);
        if ($classSessionId > 0) {
            /** @var ClassSession|null $row */
            $row = ClassSession::query()->with(['studentClass.subjectRecord', 'studentClass.room', 'studentClass.student'])
                ->where('id', $classSessionId)
                ->first();
            if (!$row instanceof ClassSession) {
                throw ValidationException::withMessages(['class_session_id' => ['Session not found']]);
            }

            /** @var StudentClass|null $course */
            $course = $row->getRelationValue('studentClass');
            if (!$course instanceof StudentClass || (int) ($course->TeacherID ?? 0) !== $teacherId) {
                throw ValidationException::withMessages(['class_session_id' => ['Forbidden']]);
            }

            return [
                'campus_id' => $this->resolveCampusIdFromCourse($course),
                'class_session_id' => (int) $row->id,
                'student_class_id' => (int) $row->StudentClassID,
                'session_date' => substr((string) $row->SessionDate, 0, 10),
                'start_time' => substr((string) $row->StartTime, 0, 5),
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

        /** @var StudentClass|null $course */
        $course = StudentClass::query()->with(['subjectRecord', 'room', 'student'])->find($studentClassId);
        if (!$course instanceof StudentClass || (int) ($course->TeacherID ?? 0) !== $teacherId) {
            throw ValidationException::withMessages(['student_class_id' => ['Forbidden']]);
        }

        return [
            'campus_id' => $this->resolveCampusIdFromCourse($course),
            'class_session_id' => 0,
            'student_class_id' => $studentClassId,
            'session_date' => $sessionDate,
            'start_time' => $startTime,
        ];
    }

    /**
     * @param array{class_session_id:int,student_class_id:int,session_date:string,start_time:string} $session
     * @return array<string, mixed>
     */
    private function sessionRefFromContext(array $session): array
    {
        if ($session['class_session_id'] > 0) {
            return ['class_session_id' => $session['class_session_id']];
        }

        return [
            'student_class_id' => $session['student_class_id'],
            'session_date' => $session['session_date'],
            'start_time' => $session['start_time'],
        ];
    }

    private function resolveCampusIdFromCourse(StudentClass $course): int
    {
        /** @var \Illuminate\Database\Eloquent\Model|null $room */
        $room = $course->getRelationValue('room');
        $fromRoom = (int) ($room ? ($room->getAttribute('campus_id') ?? 0) : 0);
        if ($fromRoom > 0) {
            return $fromRoom;
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $student */
        $student = $course->getRelationValue('student');
        $fromStudent = (int) ($student ? ($student->getAttribute('CampusID') ?? 0) : 0);
        if ($fromStudent > 0) {
            return $fromStudent;
        }

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
    private function serialize(TrueFitMasteryEvidence $row): array
    {
        return [
            'id' => (int) $row->id,
            'campus_id' => (int) $row->campus_id,
            'teacher_user_id' => (int) $row->teacher_user_id,
            'class_session_id' => ((int) $row->class_session_id) > 0 ? (int) $row->class_session_id : null,
            'student_class_id' => (int) $row->student_class_id,
            'session_date' => substr((string) $row->session_date, 0, 10),
            'start_time' => substr((string) $row->start_time, 0, 5),
            'status' => (string) $row->status,
            'mastery_schema_version' => (string) $row->mastery_schema_version,
            'outcome' => (string) $row->outcome,
            'next_review_window' => (string) $row->next_review_window,
            'teacher_decision' => (string) $row->teacher_decision,
            'source_remediation_id' => $row->source_remediation_id !== null ? (int) $row->source_remediation_id : null,
            'mastery' => $row->mastery_json,
            'checked_at' => optional($row->checked_at)->toIso8601String(),
        ];
    }
}
