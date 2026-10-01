<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Exceptions\SlotOccupiedException;
use App\Services\ClassSessionMaterializationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Exact-case POP strategy. The manifest is a closed allowlist, not user input. */
final class MuzhaFixedScheduleStrategy
{
    public function plan(array $parameters): array
    {
        $errors = [];
        if ((int) ($parameters['campus_id'] ?? 0) !== 16
            || ($parameters['decision_reference'] ?? null) !== 'rm-muzha-fixed-schedule-20260930') {
            $errors[] = 'case_parameters_mismatch';
        }
        $parameterErrors = $errors;
        $before = $this->inspect('before', false, $errors);
        $phase = 'before';
        if ($errors !== []) {
            $afterErrors = [];
            $this->inspect('after', false, $afterErrors);
            if ($afterErrors === []) {
                $errors = $parameterErrors;
                $phase = 'after';
            }
        }
        if ($phase === 'before' && $errors === []) {
            $slotGuard = app(ClassSessionMaterializationService::class);
            foreach (MuzhaFixedScheduleManifest::cases() as $case) {
                foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
                    if ($status !== 'scheduled' || $start === $case['new']) continue;
                    $session = ClassSession::query()->find($sessionId);
                    if (!$session) { $errors[] = "occurrence_missing_{$sessionId}"; continue; }
                    $session->setAttribute('StartTime', $case['new'] . ':00');
                    $session->setAttribute('EndTime', $this->end($case['new']) . ':00');
                    try {
                        $slotGuard->assertStudentSlotAvailableForSession($session);
                    } catch (SlotOccupiedException $e) {
                        $errors[] = "student_slot_conflict_{$sessionId}";
                    } catch (Throwable $e) {
                        $errors[] = "slot_check_failed_{$sessionId}";
                    }
                }
            }
        }
        return [
            'ok' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'state' => $phase,
            'class_ids' => array_keys(MuzhaFixedScheduleManifest::cases()),
            'occurrence_count' => 32, 'time_updates' => 20,
            'matching_exceptions_adopted' => 4, 'cancelled_rows_preserved' => 8,
            'snapshot' => $errors === [] ? $before : [],
        ];
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('muzha_plan_not_ready');
        }
        return DB::transaction(function () use ($context): array {
            $errors = [];
            $snapshot = $this->inspect('before', true, $errors);
            if ($errors !== []) {
                throw new RuntimeException('muzha_precondition_drift:' . implode(',', array_unique($errors)));
            }
            foreach (MuzhaFixedScheduleManifest::cases() as $classId => $case) {
                if ($case['old'] !== $case['new']) {
                    $course = StudentClass::query()->findOrFail($classId);
                    $course->setAttribute('time', $case['new'] . ':00');
                    $course->save();
                }
                foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
                    if ($status !== 'scheduled' || ($start === $case['new'] && !$exception)) continue;
                    $row = ClassSession::query()->findOrFail($sessionId);
                    $row->setAttribute('StartTime', $case['new'] . ':00');
                    $row->setAttribute('EndTime', $this->end($case['new']) . ':00');
                    $row->setAttribute('IsContractException', 0);
                    $row->save();
                }
            }
            $afterErrors = [];
            $this->inspect('after', true, $afterErrors);
            if ($afterErrors !== []) {
                throw new RuntimeException('muzha_postcondition_failed:' . implode(',', array_unique($afterErrors)));
            }
            SecurityAuditEvent::append('pop.muzha_fixed_schedule', 'success', [
                'campus_id' => 16, 'actor_type' => 'pop-runner',
                'subject_type' => 'student_class', 'subject_id' => 3428,
            ], [
                'reason_code' => 'rm_muzha_fixed_schedule_20260930',
                'operation_id' => (string) ($context['operation_id'] ?? ''),
                'course_count' => 4, 'occurrence_count' => 32,
                'outcome' => 'success',
            ]);
            return ['ok' => true, 'snapshot' => $snapshot, 'class_ids' => array_keys(MuzhaFixedScheduleManifest::cases()),
                'time_updates' => 20, 'matching_exceptions_adopted' => 4, 'cancelled_rows_preserved' => 8];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = [];
        if (!($result['ok'] ?? false)) $errors[] = 'execution_result_missing';
        $this->inspect('after', false, $errors);
        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'checks' => ['four_course_contracts', 'exact_32_occurrences', 'cancelled_rows', 'counters', 'exception_flags']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        if (($snapshot['class_ids'] ?? null) !== array_keys(MuzhaFixedScheduleManifest::cases())
            || count($snapshot['sessions'] ?? []) !== 32) {
            throw new RuntimeException('muzha_rollback_snapshot_invalid');
        }
        return DB::transaction(function () use ($snapshot): array {
            $errors = [];
            $this->inspect('after', true, $errors);
            if ($errors !== []) throw new RuntimeException('muzha_rollback_drift:' . implode(',', array_unique($errors)));
            foreach (MuzhaFixedScheduleManifest::cases() as $classId => $case) {
                $course = StudentClass::query()->findOrFail($classId);
                if ($case['old'] !== $case['new']) {
                    $course->setAttribute('time', $snapshot['contracts'][$classId]['time']);
                    $course->save();
                }
                foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
                    if ($status !== 'scheduled' || ($start === $case['new'] && !$exception)) continue;
                    $original = $snapshot['sessions'][$sessionId];
                    // The observer deliberately recomputes exception flags on
                    // normal edits. Restoring this verified legacy snapshot
                    // requires the exact original flag, even for drifted rows.
                    DB::table('ClassSession')->where('id', $sessionId)->update([
                        'StartTime' => $original['StartTime'],
                        'EndTime' => $original['EndTime'],
                        'IsContractException' => $original['IsContractException'],
                    ]);
                }
            }
            $this->inspect('before', true, $errors);
            if ($errors !== []) throw new RuntimeException('muzha_rollback_verify_failed:' . implode(',', array_unique($errors)));
            SecurityAuditEvent::append('pop.muzha_fixed_schedule.rollback', 'success', [
                'campus_id' => 16, 'actor_type' => 'pop-runner', 'subject_type' => 'student_class', 'subject_id' => 3428,
            ], ['reason_code' => 'rm_muzha_fixed_schedule_20260930', 'outcome' => 'success']);
            return ['ok' => true, 'class_ids' => array_keys(MuzhaFixedScheduleManifest::cases())];
        }, 3);
    }

    private function inspect(string $phase, bool $lock, array &$errors): array
    {
        $cases = MuzhaFixedScheduleManifest::cases();
        $snapshot = ['class_ids' => array_keys($cases), 'contracts' => [], 'sessions' => []];
        foreach ($cases as $classId => $case) {
            $student = DB::table('Student')->where('id', $case['student'])->first(['id', 'name', 'CampusID']);
            if (!$student || $student->name !== $case['name'] || (int) $student->CampusID !== 16) {
                $errors[] = "student_identity_{$classId}";
            }
            $courseQuery = StudentClass::query()->whereKey($classId);
            $course = ($lock ? $courseQuery->lockForUpdate() : $courseQuery)->first();
            $expectedStart = $phase === 'before' ? $case['old'] : $case['new'];
            if (!$course || (int) $course->StudentID !== $case['student']
                || (int) $course->TeacherID !== 29 || (int) $course->SubjectID !== 66
                || (string) $course->ClassType !== 'one_on_two' || (string) $course->ScheduleMode !== 'count'
                || (int) $course->Stop !== 0 || (int) $course->week !== 6
                || substr((string) $course->time, 0, 5) !== $expectedStart
                || substr((string) $course->StartDate, 0, 10) !== $case['start']
                || (int) $course->SessionDuration !== 120
                || (int) $course->SessionCount !== $case['count']
                || (int) $course->UsedSessions !== $case['used']
                || (int) $course->RemainingSessions !== $case['remaining']
                || ($course->EndDate ? substr((string) $course->EndDate, 0, 10) : null) !== $case['end']) {
                $errors[] = "contract_{$classId}";
            }
            if ($course) $snapshot['contracts'][$classId] = ['time' => (string) $course->time];
            $rowQuery = ClassSession::query()->where('StudentClassID', $classId)
                ->whereDate('SessionDate', '>=', '2026-10-01');
            $rows = ($lock ? $rowQuery->lockForUpdate() : $rowQuery)->get()->keyBy('id');
            if ($rows->count() !== count($case['rows']) || array_diff(array_keys($case['rows']), $rows->keys()->all())) {
                $errors[] = "occurrence_set_{$classId}";
            }
            foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
                $row = $rows->get($sessionId);
                if (!$row) { $errors[] = "occurrence_missing_{$sessionId}"; continue; }
                $expectedRowStart = $phase === 'after' && $status === 'scheduled' ? $case['new'] : $start;
                $expectedException = $phase === 'after' && $status === 'scheduled' ? 0 : $exception;
                if (substr((string) $row->SessionDate, 0, 10) !== $date
                    || substr((string) $row->StartTime, 0, 5) !== $expectedRowStart
                    || substr((string) $row->EndTime, 0, 5) !== $this->end($expectedRowStart)
                    || (string) $row->Status !== $status
                    || (int) $row->IsContractException !== $expectedException) {
                    $errors[] = "occurrence_{$sessionId}";
                }
                if ($phase === 'before' && $status === 'scheduled' && $start !== $case['new']) {
                    if (DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->exists()
                        || DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->where('Status', 'approved')->exists()) {
                        $errors[] = "immutable_{$sessionId}";
                    }
                }
                $snapshot['sessions'][$sessionId] = [
                    'StartTime' => (string) $row->StartTime, 'EndTime' => (string) $row->EndTime,
                    'IsContractException' => (int) $row->IsContractException,
                ];
            }
        }
        return $snapshot;
    }

    private function end(string $start): string
    {
        return Carbon::createFromFormat('H:i', $start)->addHours(2)->format('H:i');
    }
}
