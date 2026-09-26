<?php

namespace App\Services\TrueFit;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\TrueFitObservation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Create/read TrueFit teacher observations (structured JSON).
 * Never writes LearningRecord, attendance, enrollment, or billing.
 */
final class TrueFitObservationService
{
    public function __construct(
        private TrueFitTeacherObservationContract $contract,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getForTeacher(Request $request, int $teacherId): array
    {
        $query = TrueFitObservation::query()->where('teacher_user_id', $teacherId);
        $this->applySessionFilters($query, $request);

        /** @var TrueFitObservation|null $row */
        $row = $query->orderByDesc('id')->first();
        if (!$row instanceof TrueFitObservation) {
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

        $payload = $request->input('observation');
        if (!is_array($payload)) {
            throw ValidationException::withMessages([
                'observation' => ['observation object is required'],
            ]);
        }

        $payload['schema_version'] = TrueFitTeacherObservationContract::SCHEMA_VERSION;
        $payload['session_ref'] = $this->sessionRefFromContext($session);
        if (!isset($payload['observed_at']) || trim((string) $payload['observed_at']) === '') {
            $payload['observed_at'] = Carbon::now(config('app.timezone', 'Asia/Taipei'))->toIso8601String();
        }

        try {
            $payload = $this->contract->assertValid($payload);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'observation' => [$e->getMessage()],
            ]);
        }

        /** @var TrueFitObservation $row */
        $row = TrueFitObservation::query()->updateOrCreate(
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
                'observation_schema_version' => TrueFitTeacherObservationContract::SCHEMA_VERSION,
                'confidence' => (string) $payload['confidence'],
                'observation_json' => $payload,
                'observed_at' => Carbon::parse((string) $payload['observed_at']),
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
    private function serialize(TrueFitObservation $row): array
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
            'observation_schema_version' => (string) $row->observation_schema_version,
            'confidence' => (string) $row->confidence,
            'observation' => $row->observation_json,
            'observed_at' => optional($row->observed_at)->toIso8601String(),
        ];
    }
}
