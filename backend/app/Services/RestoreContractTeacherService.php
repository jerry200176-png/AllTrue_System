<?php

namespace App\Services;

use App\Exceptions\RestoreContractTeacherException;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\LearningRecordTeacherChange;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\TeacherProfileDirectory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Named command: RestoreContractTeacher (ADR-005).
 *
 * Restores a single ClassSession occurrence to the authoritative contract teacher
 * from StudentClass.TeacherID. Never trusts frontend-supplied teacher identities.
 */
class RestoreContractTeacherService
{
    /**
     * Teacher-identity keys that must not influence restore targeting.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TEACHER_KEYS = [
        'teacher_id',
        'substitute_teacher_id',
        'contract_teacher_id',
        'original_teacher_id',
        'effective_teacher_id',
        'restored_teacher_id',
        'new_teacher_id',
    ];

    /**
     * @param  array<string, mixed>  $input  Optional reason only; teacher identity keys rejected.
     * @param  list<int>  $authCampusIds  Empty for super_admin (no campus filter).
     * @return array<string, mixed>
     */
    public function execute(
        int $sessionId,
        array $input,
        string $authRole,
        array $authCampusIds = [],
        int $authUserId = 0
    ): array {
        foreach (self::FORBIDDEN_TEACHER_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                throw new RestoreContractTeacherException(
                    '恢復正班老師不可傳入老師身分欄位；目標由後端合約老師決定',
                    422,
                    'teacher_identity_not_accepted',
                    ['rejected_key' => $key]
                );
            }
        }

        if (!in_array($authRole, ['director', 'super_admin', 'admin'], true)) {
            throw new RestoreContractTeacherException('Forbidden', 403, 'forbidden');
        }

        $session = ClassSession::find($sessionId);
        if (!$session) {
            throw new RestoreContractTeacherException('找不到該堂次', 404, 'session_not_found');
        }

        $studentClass = StudentClass::find($session->StudentClassID);
        if (!$studentClass) {
            throw new RestoreContractTeacherException('找不到對應課程', 404, 'course_not_found');
        }

        $student = Student::find($studentClass->StudentID);
        if (!$student) {
            throw new RestoreContractTeacherException('找不到該課程的學生資料', 422, 'student_not_found');
        }

        $campusId = (int) ($student->CampusID ?? 0);
        if ($campusId <= 0) {
            throw new RestoreContractTeacherException(
                '學生未設定分校，無法恢復正班老師',
                422,
                'campus_missing'
            );
        }

        if ($authRole !== 'super_admin' && !empty($authCampusIds) && !in_array($campusId, $authCampusIds, true)) {
            throw new RestoreContractTeacherException('Forbidden', 403, 'forbidden_campus');
        }

        $contractTeacherId = (int) ($studentClass->TeacherID ?? 0);
        if ($contractTeacherId <= 0) {
            throw new RestoreContractTeacherException(
                '課程未設定正班老師，無法恢復',
                422,
                'contract_teacher_missing'
            );
        }

        try {
            $sessionDate = Carbon::parse($session->SessionDate)->toDateString();
        } catch (\Throwable $e) {
            throw new RestoreContractTeacherException('堂次日期格式無效', 422, 'invalid_session_date');
        }

        $startTime = $this->normalizeTime($session->StartTime ?? '');
        if ($startTime === '') {
            throw new RestoreContractTeacherException(
                '堂次起迄時間不完整，無法恢復正班老師',
                422,
                'invalid_session_time'
            );
        }

        $reason = $this->scrubUtf8($input['reason'] ?? '回復正班老師') ?: '回復正班老師';

        return DB::transaction(function () use (
            $session,
            $studentClass,
            $sessionDate,
            $startTime,
            $contractTeacherId,
            $authUserId,
            $reason
        ) {
            $courseId = (int) $studentClass->ID;

            $rescheduled = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'rescheduled')
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->orderByDesc('id')
                ->first();

            $scheduled = null;
            if ($rescheduled) {
                $scheduled = Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->where('original_schedule_id', (int) $rescheduled->id)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->first();
            }

            $scheduledDeleted = 0;
            $anchorIds = [];
            if ($rescheduled) {
                $anchorIds[] = (int) $rescheduled->id;
            }
            if ($scheduled && (int) ($scheduled->original_schedule_id ?? 0) > 0) {
                $anchorIds[] = (int) $scheduled->original_schedule_id;
            }

            // Defensive recovery: contract teacher may already have changed, but stale
            // substitute rows (teacher_id != current contract teacher) still remain.
            $fallbackAnchors = Schedule::where('student_course_id', $courseId)
                ->whereDate('schedule_date', $sessionDate)
                ->where('status', 'scheduled')
                ->whereNotNull('original_schedule_id')
                ->where('teacher_id', '!=', $contractTeacherId)
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                ->pluck('original_schedule_id')
                ->filter(fn ($id) => (int) $id > 0)
                ->map(fn ($id) => (int) $id)
                ->all();
            $anchorIds = array_values(array_unique(array_merge($anchorIds, $fallbackAnchors)));

            if (!empty($anchorIds)) {
                $scheduledDeleted += Schedule::where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $sessionDate)
                    ->where('status', 'scheduled')
                    ->whereIn('original_schedule_id', $anchorIds)
                    ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$startTime])
                    ->delete();
            } elseif ($scheduled) {
                $scheduledDeleted += Schedule::where('id', (int) $scheduled->id)->delete();
            }

            $rescheduledDeleted = 0;
            if (!empty($anchorIds)) {
                // MySQL/MariaDB reject DELETE with subquery on same table (error 1093).
                $stillLinked = DB::table('schedules')
                    ->where('status', 'scheduled')
                    ->whereIn('original_schedule_id', $anchorIds)
                    ->pluck('original_schedule_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $safeAnchorIds = array_values(array_diff($anchorIds, $stillLinked));
                if (!empty($safeAnchorIds)) {
                    $rescheduledDeleted = Schedule::where('student_course_id', $courseId)
                        ->whereDate('schedule_date', $sessionDate)
                        ->where('status', 'rescheduled')
                        ->whereIn('id', $safeAnchorIds)
                        ->delete();
                }
            } elseif ($rescheduled) {
                $rescheduledDeleted = Schedule::where('id', (int) $rescheduled->id)->delete();
            }

            $lrId = null;
            $lrTable = (new LearningRecord())->getTable();
            $lrRowQuery = DB::table($lrTable)->where('ClassSessionID', $session->id);
            if (Schema::hasColumn($lrTable, 'VoidedAt')) {
                $lrRowQuery->whereNull('VoidedAt');
            }
            $lrRow = $lrRowQuery->first();
            if ($lrRow) {
                $lrId = (int) $lrRow->id;
                $lrOldTeacher = (int) ($lrRow->TeacherID ?? 0);
                if ($lrOldTeacher !== $contractTeacherId) {
                    $lrUpdate = ['TeacherID' => $contractTeacherId];
                    if (Schema::hasColumn($lrTable, 'updated_at')) {
                        $lrUpdate['updated_at'] = now();
                    }
                    DB::table($lrTable)->where('id', $lrId)->update($lrUpdate);

                    if (Schema::hasTable('learning_record_teacher_changes')) {
                        try {
                            LearningRecordTeacherChange::create([
                                'learning_record_id' => $lrId,
                                'old_teacher_id' => $lrOldTeacher > 0 ? $lrOldTeacher : null,
                                'new_teacher_id' => $contractTeacherId,
                                'changed_by' => $authUserId > 0 ? $authUserId : null,
                                'reason' => $reason,
                            ]);
                        } catch (\Throwable $auditEx) {
                            Log::warning('restore_contract_teacher: learning_record_teacher_changes insert skipped', [
                                'learning_record_id' => $lrId,
                                'message' => $auditEx->getMessage(),
                            ]);
                        }
                    }

                    if (($lrRow->Status ?? '') === 'approved' && Schema::hasColumn('User', 'TeachingSessionCount')) {
                        try {
                            if ($lrOldTeacher > 0) {
                                User::where('id', $lrOldTeacher)
                                    ->where('TeachingSessionCount', '>', 0)
                                    ->decrement('TeachingSessionCount');
                            }
                            User::where('id', $contractTeacherId)->increment('TeachingSessionCount');
                        } catch (\Throwable $kpiEx) {
                            Log::warning('restore_contract_teacher: TeachingSessionCount update skipped', [
                                'message' => $kpiEx->getMessage(),
                            ]);
                        }
                    }
                }
            }

            if (Schema::hasTable('Notifications') && Schema::hasColumn('Notifications', 'ResolvedAt')) {
                DB::table('Notifications')
                    ->where('Type', 'substitute')
                    ->where('SourceType', 'ClassSession')
                    ->where('SourceID', $session->id)
                    ->whereNull('ResolvedAt')
                    ->update(['ResolvedAt' => now()]);
            }

            $teacherName = $this->scrubUtf8(TeacherProfileDirectory::nameFor($contractTeacherId, ''));
            if ($teacherName === '') {
                $teacherName = '未指派';
            }

            Log::info('[restore_contract_teacher]', [
                'class_session_id' => $session->id,
                'student_class_id' => $courseId,
                'restored_teacher_id' => $contractTeacherId,
                'scheduled_deleted' => $scheduledDeleted,
                'rescheduled_deleted' => $rescheduledDeleted,
                'operator_id' => $authUserId ?: null,
                'operation_type' => 'restore_contract_teacher',
            ]);

            return [
                'message' => '已回復正班老師',
                'code' => 'restore_contract_teacher',
                'operation_type' => 'restore_contract_teacher',
                'class_session_id' => (int) $session->id,
                'restored_teacher_id' => $contractTeacherId,
                'restored_teacher_name' => $teacherName,
                'substitute_cleared' => ($scheduledDeleted + $rescheduledDeleted) > 0,
                'deleted_scheduled_count' => $scheduledDeleted,
                'deleted_rescheduled_count' => $rescheduledDeleted,
                'learning_record_id' => $lrId,
            ];
        });
    }

    private function normalizeTime($value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i');
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $s;
    }

    /**
     * @param  mixed  $value
     */
    private function scrubUtf8($value): string
    {
        if ($value === null) {
            return '';
        }
        $s = is_string($value) ? $value : (string) $value;
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($s, 'UTF-8');
        }
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return $converted !== false ? $converted : '';
    }
}
