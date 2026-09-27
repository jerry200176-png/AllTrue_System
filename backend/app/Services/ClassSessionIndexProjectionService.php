<?php

namespace App\Services;

use App\Http\Controllers\StudentClassController;
use App\Models\Schedule;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Read-only contract projection for ClassSession index / TrueFit.
 * Must not perform auto-materialization or any writes.
 */
class ClassSessionIndexProjectionService
{
    /** @param array<string, mixed>|null $diagnostic */
    public static function isCountContractCapped(?array $diagnostic): bool
    {
        return $diagnostic !== null
            && !empty($diagnostic['is_session_mode'])
            && (int) ($diagnostic['session_count'] ?? 0) > 0
            && (int) ($diagnostic['expected_used'] ?? 0) >= (int) ($diagnostic['session_count'] ?? 0);
    }

    /**
     * @param  array<string, list<object>>  $materializedByClass
     * @param  list<int>  $requestedClassIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function buildProjectedByClassForIndex(
        Request $request,
        array $materializedByClass,
        ?string $rangeStart,
        ?string $rangeEnd,
        array $requestedClassIds = [],
        bool $includeTeacherCandidates = false
    ): array {
        if (!$rangeStart || !$rangeEnd) {
            return [];
        }

        $classIds = array_values(array_unique(array_filter(array_merge(
            array_map('intval', array_keys($materializedByClass)),
            array_map('intval', $requestedClassIds)
        ), fn ($id) => $id > 0)));

        if ($includeTeacherCandidates && (string) $request->attributes->get('auth_role') === 'teacher') {
            $teacherId = (int) $request->attributes->get('auth_teacher_id');
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
            if ($requestedCampus > 0) {
                $campusIds = [$requestedCampus];
            }

            $candidateQuery = StudentClass::query()
                ->where('TeacherID', $teacherId)
                ->where(function ($q) {
                    $q->where('Stop', 0)->orWhereNull('Stop');
                })
                ->where(function ($q) use ($rangeStart, $rangeEnd) {
                    $q->where(function ($dates) use ($rangeEnd) {
                        $dates->whereNull('StartDate')->orWhereDate('StartDate', '<=', $rangeEnd);
                    })->where(function ($dates) use ($rangeStart) {
                        $dates->whereNull('EndDate')->orWhereDate('EndDate', '>=', $rangeStart);
                    });
                });
            if (!empty($campusIds)) {
                $candidateQuery->where(function ($q) use ($campusIds) {
                    $q->whereHas('room', fn ($room) => $room->whereIn('campus_id', $campusIds))
                        ->orWhere(function ($noRoom) use ($campusIds) {
                            $noRoom->whereNull('room_id')
                                ->whereHas('student', fn ($student) => $student->whereIn('CampusID', $campusIds));
                        });
                });
            }
            $classIds = array_values(array_unique(array_merge(
                $classIds,
                $candidateQuery->pluck('ID')->map(fn ($id) => (int) $id)->all()
            )));
        }
        if ($classIds === []) {
            return [];
        }

        $classesQuery = StudentClass::query()->with(['student', 'teacher', 'subjectRecord', 'room']);
        $classesQuery->whereIn('ID', $classIds);
        $classes = $classesQuery->get()->keyBy('ID');
        $capacityDiagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics($classIds);
        $scheduleSince = $classes->pluck('StartDate')->filter()->map(function ($date) use ($rangeStart) {
            try {
                return Carbon::parse((string) $date)->toDateString();
            } catch (\Throwable $e) {
                return $rangeStart;
            }
        })->min() ?: $rangeStart;
        $schedules = Schedule::query()
            ->whereIn('student_course_id', $classIds)
            ->whereDate('schedule_date', '>=', $scheduleSince)
            ->whereDate('schedule_date', '<=', $rangeEnd)
            ->select('student_course_id', 'schedule_date', 'status')
            ->get();

        $leaveByClass = [];
        $scheduledByClass = [];
        foreach ($schedules as $row) {
            $id = (int) $row->student_course_id;
            $d = $row->schedule_date ? Carbon::parse($row->schedule_date)->toDateString() : null;
            if (!$d) {
                continue;
            }
            if ($row->status === 'scheduled') {
                $scheduledByClass[$id][$d] = true;
            } else {
                $leaveByClass[$id][$d] = true;
            }
        }

        $reader = app(SessionProjectionReadService::class);
        $studentClassController = app(StudentClassController::class);
        /** @var array<string, list<array<string, mixed>>> $projectedByClass */
        $projectedByClass = [];

        /** @var array<int, list<object>> $rowsByClassId */
        $rowsByClassId = [];
        foreach ($materializedByClass as $classKey => $classRows) {
            $rowsByClassId[(int) $classKey] = $classRows;
        }

        foreach ($classIds as $classId) {
            $class = $classes->get($classId);
            if (!$class) {
                continue;
            }
            if (self::isCountContractCapped($capacityDiagnostics[$classId] ?? null)) {
                continue;
            }
            $rows = $rowsByClassId[$classId] ?? [];

            $materialized = [];
            foreach ($rows as $row) {
                $materialized[] = $reader->materializedSlot(
                    (int) $row->id,
                    $classId,
                    (string) ($row->session_date ?? ''),
                    (string) ($row->start_time ?? ''),
                    (string) ($row->end_time ?? ''),
                    (string) ($row->status ?? 'scheduled')
                );
            }

            $existingSet = [];
            foreach ($materialized as $slot) {
                $existingSet[$slot['session_date']] = true;
            }

            $effectiveDates = [];
            if ((string) ($class->ScheduleMode ?? '') === 'date') {
                $effectiveDates = $studentClassController->computeMonthlyEffectiveSessionDates(
                    $class,
                    $rangeStart,
                    $rangeEnd,
                    $leaveByClass[$classId] ?? [],
                    $scheduledByClass[$classId] ?? [],
                    $existingSet
                );
            } elseif (
                $includeTeacherCandidates
                && (string) ($class->ScheduleMode ?? 'count') === 'count'
                && (int) ($class->SessionCount ?? 0) > 0
                && (string) ($class->scheduling_policy ?? 'auto_recurrence') !== 'manual_occurrence'
                && (int) ($class->PackageID ?? 0) <= 0
                && $class->StartDate
            ) {
                $daysOfWeek = [];
                foreach (['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'] as $field) {
                    $day = (int) ($class->{$field} ?? 0);
                    if ($day >= 1 && $day <= 7 && !in_array($day, $daysOfWeek, true)) {
                        $daysOfWeek[] = $day;
                    }
                }
                if ($daysOfWeek !== []) {
                    $contractDates = StudentClassController::computeEffectiveSessionDates(
                        Carbon::parse($class->StartDate)->toDateString(),
                        (int) $class->SessionCount,
                        $daysOfWeek,
                        $leaveByClass[$classId] ?? [],
                        $scheduledByClass[$classId] ?? []
                    );
                    $effectiveDates = array_values(array_filter(
                        $contractDates,
                        fn ($date) => $date >= $rangeStart && $date <= $rangeEnd
                    ));
                }
            }

            $projected = $reader->buildProjectedFromEffectiveDates(
                $classId,
                $effectiveDates,
                $materialized,
                $class
            );
            if (!empty($projected)) {
                $projectedByClass[(string) $classId] = $projected;
            }
        }

        return $projectedByClass;
    }
}
