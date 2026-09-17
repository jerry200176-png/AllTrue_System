<?php

namespace App\Services\TrueFit;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\TrueFitDiagnosis;
use App\Models\TrueFitLessonPrep;
use App\Models\TrueFitMasteryEvidence;
use App\Models\TrueFitObservation;
use App\Models\TrueFitRemediation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only aggregate stage presence for TrueFit workspace cards. No writes / no new tables. */
final class TrueFitSessionProgressService
{
    public const MAX_SESSIONS = 40;

    public const STAGE_KEYS = ['prep', 'observation', 'diagnosis', 'remediation', 'mastery'];

    /**
     * @return array{data: list<array{session_ref: array<string,mixed>, progress: array<string,bool>}>, meta: array<string,mixed>}
     */
    public function progressForTeacher(Request $request, int $teacherId): array
    {
        $rawSessions = $request->input('sessions');
        if (!is_array($rawSessions)) {
            throw ValidationException::withMessages(['sessions' => ['sessions must be an array of session refs']]);
        }
        if (count($rawSessions) > self::MAX_SESSIONS) {
            throw ValidationException::withMessages(['sessions' => ['At most ' . self::MAX_SESSIONS . ' sessions per request']]);
        }

        $requested = count($rawSessions);
        $parsed = [];
        foreach ($rawSessions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = $this->normalizeSessionRef($row);
            if ($ref !== null) {
                $parsed[] = $ref;
            }
        }

        $allowed = $this->filterAccessibleRefs($parsed, $teacherId, $request);
        $presenceByKey = $this->loadPresenceMaps($teacherId, $allowed);

        $data = [];
        foreach ($allowed as $ref) {
            $key = $this->refKey($ref);
            $data[] = [
                'session_ref' => $this->publicSessionRef($ref),
                'progress' => $presenceByKey[$key] ?? $this->emptyProgress(),
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'requested' => $requested,
                'returned' => count($data),
                'omitted' => max(0, $requested - count($data)),
                'max_sessions' => self::MAX_SESSIONS,
                // Fail-closed: inaccessible/invalid refs omitted (no per-id 403 leak).
                'contract' => 'omit_inaccessible',
            ],
        ];
    }

    /** @param array<string,mixed> $row */
    private function normalizeSessionRef(array $row): ?array
    {
        $classSessionId = (int) ($row['class_session_id'] ?? 0);
        if ($classSessionId > 0) {
            return ['kind' => 'materialized', 'class_session_id' => $classSessionId];
        }

        $studentClassId = (int) ($row['student_class_id'] ?? 0);
        $sessionDate = substr((string) ($row['session_date'] ?? ''), 0, 10);
        $startTime = substr((string) ($row['start_time'] ?? ''), 0, 5);
        if ($studentClassId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate) || !preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            return null;
        }

        return [
            'kind' => 'projected',
            'student_class_id' => $studentClassId,
            'session_date' => $sessionDate,
            'start_time' => $startTime,
        ];
    }

    /** @param list<array<string,mixed>> $refs @return list<array<string,mixed>> */
    private function filterAccessibleRefs(array $refs, int $teacherId, Request $request): array
    {
        $materializedIds = [];
        $projected = [];
        foreach ($refs as $ref) {
            if ($ref['kind'] === 'materialized') {
                $materializedIds[] = (int) $ref['class_session_id'];
            } else {
                $projected[] = $ref;
            }
        }
        $materializedIds = array_values(array_unique($materializedIds));

        $allowedMaterialized = [];
        if ($materializedIds !== []) {
            foreach (ClassSession::query()->whereIn('id', $materializedIds)->with(['studentClass.room', 'studentClass.student'])->get() as $row) {
                $course = $row->getRelationValue('studentClass');
                if (!$course instanceof StudentClass || (int) ($course->TeacherID ?? 0) !== $teacherId) {
                    continue;
                }
                if (!$this->campusAllowed($request, $this->resolveCampusIdFromCourse($course))) {
                    continue;
                }
                $allowedMaterialized[(int) $row->id] = true;
            }
        }

        $allowedProjected = [];
        if ($projected !== []) {
            $classIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['student_class_id'], $projected)));
            $courses = StudentClass::query()->with(['room', 'student'])->whereIn('ID', $classIds)->where('TeacherID', $teacherId)->get()->keyBy('ID');
            foreach ($projected as $ref) {
                $course = $courses->get((int) $ref['student_class_id']);
                if (!$course instanceof StudentClass) {
                    continue;
                }
                if (!$this->campusAllowed($request, $this->resolveCampusIdFromCourse($course))) {
                    continue;
                }
                $allowedProjected[$this->refKey($ref)] = $ref;
            }
        }

        $out = [];
        $seen = [];
        foreach ($refs as $ref) {
            $key = $this->refKey($ref);
            if (isset($seen[$key])) {
                continue;
            }
            $ok = $ref['kind'] === 'materialized'
                ? isset($allowedMaterialized[(int) $ref['class_session_id']])
                : isset($allowedProjected[$key]);
            if (!$ok) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $ref;
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $allowed @return array<string, array<string,bool>> */
    private function loadPresenceMaps(int $teacherId, array $allowed): array
    {
        $maps = [];
        foreach ($allowed as $ref) {
            $maps[$this->refKey($ref)] = $this->emptyProgress();
        }
        if ($allowed === []) {
            return $maps;
        }

        $materializedIds = [];
        $projected = [];
        foreach ($allowed as $ref) {
            if ($ref['kind'] === 'materialized') {
                $materializedIds[] = (int) $ref['class_session_id'];
            } else {
                $projected[] = $ref;
            }
        }
        $materializedIds = array_values(array_unique($materializedIds));

        $stages = [
            'prep' => TrueFitLessonPrep::class,
            'observation' => TrueFitObservation::class,
            'diagnosis' => TrueFitDiagnosis::class,
            'remediation' => TrueFitRemediation::class,
            'mastery' => TrueFitMasteryEvidence::class,
        ];

        foreach ($stages as $stage => $modelClass) {
            foreach ($this->queryArtifactRows($modelClass, $teacherId, $materializedIds, $projected) as $row) {
                $classSessionId = (int) ($row->getAttribute('class_session_id') ?? 0);
                $key = $classSessionId > 0
                    ? 'm:' . $classSessionId
                    : 'p:' . (int) $row->getAttribute('student_class_id')
                        . '|' . substr((string) $row->getAttribute('session_date'), 0, 10)
                        . '|' . substr((string) $row->getAttribute('start_time'), 0, 5);
                if (isset($maps[$key])) {
                    $maps[$key][$stage] = true;
                }
            }
        }

        return $maps;
    }

    /**
     * @param class-string $modelClass
     * @param list<int> $materializedIds
     * @param list<array<string,mixed>> $projected
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function queryArtifactRows(string $modelClass, int $teacherId, array $materializedIds, array $projected): Collection
    {
        if ($materializedIds === [] && $projected === []) {
            return collect();
        }

        $query = $modelClass::query()->where('teacher_user_id', $teacherId);
        $query->where(function ($outer) use ($materializedIds, $projected) {
            $first = true;
            if ($materializedIds !== []) {
                $outer->whereIn('class_session_id', $materializedIds);
                $first = false;
            }
            foreach ($projected as $ref) {
                $clause = function ($inner) use ($ref) {
                    $inner->where('class_session_id', 0)
                        ->where('student_class_id', (int) $ref['student_class_id'])
                        ->whereDate('session_date', (string) $ref['session_date'])
                        ->where('start_time', (string) $ref['start_time']);
                };
                if ($first) {
                    $outer->where($clause);
                    $first = false;
                } else {
                    $outer->orWhere($clause);
                }
            }
        });

        return $query->get(['class_session_id', 'student_class_id', 'session_date', 'start_time']);
    }

    private function refKey(array $ref): string
    {
        if ($ref['kind'] === 'materialized') {
            return 'm:' . (int) $ref['class_session_id'];
        }

        return 'p:' . (int) $ref['student_class_id'] . '|' . (string) $ref['session_date'] . '|' . (string) $ref['start_time'];
    }

    private function publicSessionRef(array $ref): array
    {
        if ($ref['kind'] === 'materialized') {
            return ['class_session_id' => (int) $ref['class_session_id']];
        }

        return [
            'student_class_id' => (int) $ref['student_class_id'],
            'session_date' => (string) $ref['session_date'],
            'start_time' => (string) $ref['start_time'],
        ];
    }

    private function emptyProgress(): array
    {
        return [
            'prep' => false,
            'observation' => false,
            'diagnosis' => false,
            'remediation' => false,
            'mastery' => false,
        ];
    }

    private function resolveCampusIdFromCourse(StudentClass $course): int
    {
        $room = $course->relationLoaded('room') ? $course->getRelationValue('room') : $course->room()->first();
        $fromRoom = (int) ($room ? ($room->getAttribute('campus_id') ?? 0) : 0);
        if ($fromRoom > 0) {
            return $fromRoom;
        }
        $student = $course->relationLoaded('student') ? $course->getRelationValue('student') : $course->student()->first();
        $fromStudent = (int) ($student ? ($student->getAttribute('CampusID') ?? 0) : 0);
        if ($fromStudent > 0) {
            return $fromStudent;
        }

        return (int) ($course->by1 ?? 0);
    }

    private function campusAllowed(Request $request, int $campusId): bool
    {
        if ($campusId <= 0) {
            return false;
        }
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        if (empty($campusIds)) {
            return true;
        }

        return in_array($campusId, $campusIds, true);
    }
}
