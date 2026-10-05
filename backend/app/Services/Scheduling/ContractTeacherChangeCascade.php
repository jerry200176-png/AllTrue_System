<?php

namespace App\Services\Scheduling;

use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Support\LearningRecordMutableOwnership;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Contract-teacher change cascade (extracted from StudentClassController, ADR-003 / #966):
 * pin past history to the former teacher, move future schedule rows and mutable
 * evaluation records to the new contract teacher, and clear false pins.
 * Behaviour-preserving move; all methods are static (no collaborators).
 */
final class ContractTeacherChangeCascade
{

    /**
     * Keep past / already-taught sessions on the former contract teacher when
     * StudentClass.TeacherID changes (in-app #207).
     *
     * Calendar display prefers substitute schedule rows (original_schedule_id NOT NULL
     * + teacher_id <> contract). Without pinning, past attended sessions fall through
     * to the new contract teacher and look like history was rewritten.
     */
    public static function pinPastSessionsToFormerTeacherAfterContractTeacherChange(
        int $courseId,
        int $oldTeacherId,
        int $newTeacherId,
        ?string $effectiveDate = null
    ): void {
        if ($courseId <= 0 || $oldTeacherId <= 0 || $newTeacherId <= 0 || $oldTeacherId === $newTeacherId) {
            return;
        }
        // Sessions before this date keep the former teacher (default: today).
        $effectiveDate = $effectiveDate ?: Carbon::today()->toDateString();

        $course = DB::table('StudentClass')->where('ID', $courseId)->first();
        if (!$course) {
            return;
        }

        $studentId = (int) ($course->StudentID ?? 0);
        $campusId = $studentId > 0
            ? (int) (DB::table('Student')->where('id', $studentId)->value('CampusID') ?? 0)
            : 0;
        if ($studentId <= 0 || $campusId <= 0) {
            return;
        }

        $today = Carbon::today()->toDateString();
        $subject = (string) (DB::table('Subject')->where('id', $course->SubjectID)->value('Subject_Name') ?? '');
        $classType = (string) ($course->class_type ?? $course->ClassType ?? 'one_on_one');

        // in-app #207: only pin sessions with teaching evidence so calendar history
        // stays on the former teacher. Past rows that are still merely `scheduled`
        // must NOT become fake substitute pins — otherwise calendar keeps showing
        // the old teacher after a contract TeacherID change (in-app #312).
        $taughtStatuses = ['attended', 'late', 'leave', 'excused', 'completed', 'absent'];
        $pastSessions = DB::table('ClassSession as cs')
            ->where('cs.StudentClassID', $courseId)
            ->where(function ($q) use ($today, $taughtStatuses, $effectiveDate) {
                // Past lessons are history regardless of attendance evidence.
                $q->where(function ($before) use ($effectiveDate) {
                    $before->whereDate('cs.SessionDate', '<', $effectiveDate)
                        ->whereRaw("COALESCE(cs.Status, '') != 'cancelled'");
                })
                    ->orWhereIn('cs.Status', $taughtStatuses)
                    ->orWhere(function ($q2) use ($today) {
                        $q2->whereDate('cs.SessionDate', '<', $today)
                            ->whereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('StudentSingIn as ssi')
                                    ->whereColumn('ssi.ClassSessionID', 'cs.id');
                            });
                    })
                    // Historical LR authorship only — bare pending placeholders must
                    // not become false #207 pins (#312 / #314 Option 2B).
                    ->orWhere(function ($q2) use ($today) {
                        $q2->whereDate('cs.SessionDate', '<', $today)
                            ->whereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('LearningRecord as lr')
                                    ->whereColumn('lr.ClassSessionID', 'cs.id')
                                    ->whereNull('lr.VoidedAt')
                                    ->where(function ($hist) {
                                        $hist->whereRaw("LOWER(TRIM(COALESCE(lr.Status, ''))) != ?", ['pending'])
                                            ->orWhereNotNull('lr.ApprovedAt')
                                            ->orWhere('lr.SessionDeducted', 1)
                                            ->orWhere(function ($c) {
                                                $c->whereNotNull('lr.Content')
                                                    ->whereRaw("TRIM(lr.Content) != ''")
                                                    ->whereRaw("TRIM(lr.Content) != ?", ['（評量表）']);
                                            })
                                            ->orWhere(function ($p) {
                                                $p->whereNotNull('lr.Progress')->whereRaw("TRIM(lr.Progress) != ''");
                                            })
                                            ->orWhere(function ($p) {
                                                $p->whereNotNull('lr.NextHomework')->whereRaw("TRIM(lr.NextHomework) != ''");
                                            });
                                    });
                            });
                    });
            })
            ->orderBy('cs.SessionDate')
            ->orderBy('cs.StartTime')
            ->select('cs.*')
            ->get();

        foreach ($pastSessions as $session) {
            try {
                $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
            } catch (\Throwable $e) {
                continue;
            }
            $startTime = substr((string) ($session->StartTime ?? ''), 0, 5);
            $endTime = substr((string) ($session->EndTime ?? ''), 0, 5);
            if ($startTime === '' || $endTime === '') {
                continue;
            }

            // Already has a substitute-style exception with a non-contract teacher → leave it.
            $existingPin = DB::table('schedules')
                ->where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'scheduled')
                ->whereNotNull('original_schedule_id')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->where('teacher_id', '<>', $newTeacherId)
                ->exists();
            if ($existingPin) {
                continue;
            }

            $dayOfWeek = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
            $startM = ((int) substr($startTime, 0, 2)) * 60 + (int) substr($startTime, 3, 2);
            $endM = ((int) substr($endTime, 0, 2)) * 60 + (int) substr($endTime, 3, 2);
            $durationHours = max(0.5, round(max(0, $endM - $startM) / 60, 1));
            $now = now();

            $rescheduledId = DB::table('schedules')->insertGetId([
                'student_id' => $studentId,
                'teacher_id' => $oldTeacherId,
                'subject' => $subject,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => $durationHours,
                'class_type' => $classType,
                'status' => 'rescheduled',
                'type' => 'normal',
                'deduction' => 0,
                'branch_id' => $campusId,
                'schedule_date' => $sessionDate,
                'student_course_id' => $courseId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('schedules')->insert([
                'student_id' => $studentId,
                'teacher_id' => $oldTeacherId,
                'subject' => $subject,
                'day_of_week' => $dayOfWeek,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => $durationHours,
                'class_type' => $classType,
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => $campusId,
                'schedule_date' => $sessionDate,
                'student_course_id' => $courseId,
                'original_schedule_id' => (int) $rescheduledId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Keep future schedule rows aligned when contract teacher changes.
     */
    public static function syncFutureScheduleTeachersAfterContractTeacherChange(int $courseId, int $oldTeacherId, int $newTeacherId): void
    {
        if ($courseId <= 0 || $newTeacherId <= 0 || $oldTeacherId <= 0 || $oldTeacherId === $newTeacherId) {
            return;
        }

        $today = Carbon::today()->toDateString();

        Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', '>=', $today)
            ->whereNull('original_schedule_id')
            ->where('teacher_id', $oldTeacherId)
            ->update([
                'teacher_id' => $newTeacherId,
                'updated_at' => now(),
            ]);

        $staleAnchorIds = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', '>=', $today)
            ->whereNotNull('original_schedule_id')
            ->where('teacher_id', $oldTeacherId)
            ->pluck('original_schedule_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (!empty($staleAnchorIds)) {
            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', '>=', $today)
                ->where('status', 'scheduled')
                ->whereIn('original_schedule_id', $staleAnchorIds)
                ->delete();

            Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', '>=', $today)
                ->where('status', 'rescheduled')
                ->whereIn('id', $staleAnchorIds)
                ->delete();
        }
    }

    /** in-app #314: restamp mutable LRs onto new contract teacher; leave history/subs. */
    public static function alignMutableLearningRecordTeachersAfterContractTeacherChange(
        int $courseId,
        int $oldTeacherId,
        int $newTeacherId,
        int $changedBy,
        ?string $fromDate = null
    ): void {
        if ($courseId <= 0 || $newTeacherId <= 0 || $oldTeacherId === $newTeacherId) {
            return;
        }

        $records = LearningRecord::query()
            ->where('StudentClassID', $courseId)
            ->when($fromDate, fn ($q) => $q->whereDate('SessionDate', '>=', $fromDate))
            ->whereNull('VoidedAt')
            ->where('TeacherID', '!=', $newTeacherId)
            ->get();

        foreach ($records as $record) {
            if (!LearningRecordMutableOwnership::canFollowCurrentCourseTeacher($record)) {
                continue;
            }

            $fromTeacherId = (int) ($record->TeacherID ?? 0);
            $record->TeacherID = $newTeacherId;
            $record->save();

            if (!Schema::hasTable('learning_record_teacher_changes')) {
                continue;
            }
            try {
                DB::table('learning_record_teacher_changes')->insert([
                    'learning_record_id' => (int) $record->id,
                    'old_teacher_id' => $fromTeacherId > 0 ? $fromTeacherId : null,
                    'new_teacher_id' => $newTeacherId,
                    'changed_by' => $changedBy > 0 ? $changedBy : null,
                    'reason' => 'course_teacher_change_unperformed_occurrence',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('course_teacher_change: LR ownership audit skipped', [
                    'learning_record_id' => (int) $record->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Remove false history pins created for untaught past ClassSessions.
     * Real substitutes and taught-session #207 pins are retained.
     */
    public static function clearUntaughtPastFalseHistoryPins(int $courseId, int $currentTeacherId, ?string $fromDate = null): void
    {
        if ($courseId <= 0 || $currentTeacherId <= 0) {
            return;
        }

        $today = Carbon::today()->toDateString();
        $taughtStatuses = ['attended', 'late', 'leave', 'excused', 'completed', 'absent'];

        $pinRows = DB::table('schedules')
            ->where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->whereDate('schedule_date', '<', $today)
            ->when($fromDate, fn ($q) => $q->whereDate('schedule_date', '>=', $fromDate))
            ->where('teacher_id', '<>', $currentTeacherId)
            ->get(['id', 'original_schedule_id', 'schedule_date', 'start_time']);

        $anchorIds = [];
        $pinIds = [];
        foreach ($pinRows as $pin) {
            $sessionDate = $pin->schedule_date ? Carbon::parse((string) $pin->schedule_date)->toDateString() : '';
            $startTime = substr((string) ($pin->start_time ?? ''), 0, 5);
            if ($sessionDate === '' || $startTime === '') {
                continue;
            }

            $session = DB::table('ClassSession')
                ->where('StudentClassID', $courseId)
                ->whereDate('SessionDate', $sessionDate)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startTime])
                ->first();
            if (!$session) {
                continue;
            }

            $status = (string) ($session->Status ?? '');
            if (in_array($status, $taughtStatuses, true)) {
                continue;
            }

            $sessionId = (int) ($session->id ?? 0);
            $hasSignIn = $sessionId > 0 && DB::table('StudentSingIn')
                ->where('ClassSessionID', $sessionId)
                ->exists();
            if ($hasSignIn) {
                continue;
            }
            // Keep pin only when LR has authorship/attendance history — mutable
            // pending placeholders must follow the live contract teacher (#314).
            $keepForHistoricalLr = false;
            if ($sessionId > 0) {
                $sessionLrs = LearningRecord::query()
                    ->where('ClassSessionID', $sessionId)
                    ->whereNull('VoidedAt')
                    ->get();
                foreach ($sessionLrs as $sessionLr) {
                    if (LearningRecordMutableOwnership::hasAuthorshipOrAttendanceEvidence($sessionLr)) {
                        $keepForHistoricalLr = true;
                        break;
                    }
                }
            }
            if ($keepForHistoricalLr) {
                continue;
            }

            $anchorIds[] = (int) $pin->original_schedule_id;
            $pinIds[] = (int) $pin->id;
        }

        if (!empty($pinIds)) {
            DB::table('schedules')->whereIn('id', $pinIds)->delete();
        }

        $anchorIds = array_values(array_unique(array_filter($anchorIds)));
        if (!empty($anchorIds)) {
            DB::table('schedules')
                ->where('student_course_id', $courseId)
                ->where('status', 'rescheduled')
                ->whereIn('id', $anchorIds)
                ->delete();
        }
    }
}
