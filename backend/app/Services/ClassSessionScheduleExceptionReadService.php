<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only mirror of ClassSessionController::autoMaterializeScheduledExceptionsForRange.
 * Surfaces schedule rows that are legal today but not yet materialized as ClassSession.
 */
class ClassSessionScheduleExceptionReadService
{
    /**
     * @param  array<string, true>  $occupiedSlotKeys  classId|date|startHM
     * @return list<array<string, mixed>>
     */
    public function readSlotsForDate(
        Request $request,
        string $date,
        array $campusIds,
        int $teacherId,
        array $occupiedSlotKeys = []
    ): array {
        if ($date === '' || $teacherId <= 0) {
            return [];
        }

        $schedules = Schedule::query()
            ->join('StudentClass as sc', 'sc.ID', '=', 'schedules.student_course_id')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
            ->whereDate('schedules.schedule_date', $date)
            ->where('schedules.status', 'scheduled')
            ->where(function ($query) {
                $query->whereNull('schedules.type')->orWhere('schedules.type', '!=', 'extra');
            })
            ->whereNotNull('schedules.student_course_id')
            ->where(function ($q) {
                $q->where('sc.Stop', 0)->orWhereNull('sc.Stop');
            })
            ->where(function ($q) use ($teacherId) {
                $q->where('schedules.teacher_id', $teacherId)
                    ->orWhere(function ($inner) use ($teacherId) {
                        $inner->where('sc.TeacherID', $teacherId)
                            ->where(function ($sub) {
                                $sub->whereNull('schedules.original_schedule_id')
                                    ->orWhereColumn('schedules.teacher_id', 'sc.TeacherID');
                            });
                    });
            })
            ->when(!empty($campusIds), fn ($q) => $q->whereIn('schedules.branch_id', $campusIds))
            ->select([
                'schedules.id as schedule_id',
                'schedules.student_course_id',
                'schedules.schedule_date',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.teacher_id as schedule_teacher_id',
                'schedules.original_schedule_id',
                'schedules.branch_id',
                'sc.StudentID as student_id',
                'sc.TeacherID as course_teacher_id',
                's.name as student_name',
                DB::raw('COALESCE(sub.Subject_Name, schedules.subject, "") as subject_name'),
            ])
            ->get();

        $reader = app(SessionProjectionReadService::class);
        $out = [];

        foreach ($schedules as $schedule) {
            $anchorId = (int) ($schedule->original_schedule_id ?? 0);
            if ($anchorId > 0) {
                $anchorDate = Schedule::query()->whereKey($anchorId)->value('schedule_date');
                if ($anchorDate && Carbon::parse($anchorDate)->toDateString() !== $date) {
                    continue;
                }
            }

            $classId = (int) ($schedule->student_course_id ?? 0);
            if ($classId <= 0) {
                continue;
            }

            $startHm = substr((string) ($schedule->start_time ?? ''), 0, 5);
            $endHm = substr((string) ($schedule->end_time ?? ''), 0, 5);
            if ($startHm === '' || $endHm === '') {
                continue;
            }

            $slotKey = $classId . '|' . $date . '|' . $startHm;
            if (isset($occupiedSlotKeys[$slotKey])) {
                continue;
            }

            $exists = ClassSession::query()
                ->where('StudentClassID', $classId)
                ->whereDate('SessionDate', $date)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
                ->where('Status', '<>', 'cancelled')
                ->exists();
            if ($exists) {
                continue;
            }

            $branchId = (int) ($schedule->branch_id ?? 0);
            $out[] = array_merge(
                $reader->projectedSlot(
                    $classId,
                    $date,
                    $startHm,
                    $endHm,
                    $branchId
                ),
                [
                    'student_id' => (int) ($schedule->student_id ?? 0),
                    'student_name' => (string) ($schedule->student_name ?? ''),
                    'subject_name' => (string) ($schedule->subject_name ?? ''),
                    'source' => 'schedule_exception',
                ]
            );
            $occupiedSlotKeys[$slotKey] = true;
        }

        return $out;
    }
}
