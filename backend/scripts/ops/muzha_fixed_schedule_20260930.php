<?php

/**
 * RM-MUZHA-FIXED-SCHEDULE-20260930: exact four-course repair.
 * Run only through the protected workflow; stdout contains IDs/counts only.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClassSession;
use App\Models\StudentClass;
use Illuminate\Support\Facades\DB;

// Laravel's CLI exception handler can render an uncaught exception while
// returning exit code 0. The protected workflow must see precondition drift
// as a failed process, including during its read-only preflight.
try {
$mode = getenv('MUZHA_MODE');
$expectedConfirm = match ($mode) {
    'dry-run' => 'DRY_RUN_MUZHA_FIXED_SCHEDULE_20260930',
    'apply' => 'APPROVE_MUZHA_FIXED_SCHEDULE_20260930',
    default => null,
};
if ($expectedConfirm === null || getenv('MUZHA_CONFIRM') !== $expectedConfirm) {
    throw new RuntimeException('Invalid case mode or confirmation');
}

// Each occurrence is [date, old start, status, exception]. No additional
// future occurrence may exist when the gate runs. End is always start + 120m.
$cases = [
    3428 => [
        'student' => 373, 'name' => '張正樂', 'old' => '19:30', 'new' => '10:00',
        'count' => 8, 'used' => 4, 'remaining' => 4, 'start' => '2026-08-29', 'end' => '2026-10-24',
        'rows' => [
            33781 => ['2026-10-03', '10:00', 'scheduled', 1],
            33782 => ['2026-10-03', '19:30', 'cancelled', 0],
            33783 => ['2026-10-10', '19:30', 'scheduled', 0],
            33784 => ['2026-10-15', '19:30', 'cancelled', 0],
            42412 => ['2026-10-17', '19:30', 'scheduled', 0],
            42472 => ['2026-10-24', '19:30', 'scheduled', 0],
        ],
    ],
    3429 => [
        'student' => 374, 'name' => '張正甯', 'old' => '19:00', 'new' => '10:00',
        'count' => 8, 'used' => 4, 'remaining' => 4, 'start' => '2026-08-29', 'end' => null,
        'rows' => [
            32870 => ['2026-10-03', '10:00', 'scheduled', 1],
            32871 => ['2026-10-10', '19:00', 'scheduled', 0],
            32872 => ['2026-10-17', '19:00', 'scheduled', 0],
            32881 => ['2026-10-22', '19:00', 'cancelled', 0],
            41621 => ['2026-10-24', '19:00', 'scheduled', 0],
        ],
    ],
    2332 => [
        'student' => 155, 'name' => '吳宏逸', 'old' => '15:00', 'new' => '15:00',
        'count' => 16, 'used' => 4, 'remaining' => 12, 'start' => '2026-08-15', 'end' => '2026-12-26',
        'rows' => [
            19267 => ['2026-10-03', '15:00', 'scheduled', 1],
            19268 => ['2026-10-10', '10:00', 'scheduled', 0],
            19269 => ['2026-10-17', '10:00', 'scheduled', 0],
            19270 => ['2026-10-24', '10:00', 'scheduled', 0],
            19271 => ['2026-10-31', '10:00', 'scheduled', 0],
            19272 => ['2026-11-07', '10:00', 'scheduled', 0],
            19273 => ['2026-11-14', '10:00', 'scheduled', 0],
            19274 => ['2026-11-21', '10:00', 'scheduled', 0],
            19275 => ['2026-11-28', '10:00', 'scheduled', 0],
            32735 => ['2026-12-05', '10:00', 'scheduled', 0],
            33815 => ['2026-12-12', '10:00', 'scheduled', 0],
            35545 => ['2026-12-19', '10:00', 'scheduled', 0],
        ],
    ],
    2335 => [
        'student' => 156, 'name' => '吳宛庭', 'old' => '15:00', 'new' => '15:00',
        'count' => 16, 'used' => 13, 'remaining' => 3, 'start' => '2026-08-08', 'end' => '2026-12-05',
        'rows' => [
            19324 => ['2026-10-03', '15:00', 'scheduled', 1],
            19325 => ['2026-10-10', '15:00', 'scheduled', 1],
            19326 => ['2026-10-17', '15:00', 'scheduled', 1],
            19328 => ['2026-10-31', '10:00', 'cancelled', 0],
            19329 => ['2026-11-07', '10:00', 'cancelled', 0],
            19320 => ['2026-11-14', '10:00', 'cancelled', 0],
            19321 => ['2026-11-21', '10:00', 'cancelled', 0],
            19322 => ['2026-11-28', '10:00', 'cancelled', 0],
            33814 => ['2026-12-05', '10:00', 'scheduled', 0],
        ],
    ],
];

$result = DB::transaction(function () use ($cases, $mode) {
    $changeCount = 0;
    $adoptedCount = 0;
    $classIds = array_keys($cases);
    foreach ($cases as $classId => $case) {
        $student = DB::table('Student')->where('id', $case['student'])->first(['id', 'name', 'CampusID']);
        if (!$student || $student->name !== $case['name'] || (int) $student->CampusID !== 16) {
            throw new RuntimeException("Student identity drift: {$classId}");
        }
        $class = StudentClass::query()->whereKey($classId)->lockForUpdate()->first();
        if (!$class || (int) $class->StudentID !== $case['student']
            || (int) $class->TeacherID !== 29 || (int) $class->SubjectID !== 66
            || (string) $class->ClassType !== 'one_on_two' || (string) $class->ScheduleMode !== 'count'
            || (int) $class->Stop !== 0 || (int) $class->week !== 6
            || substr((string) $class->time, 0, 5) !== $case['old']
            || substr((string) $class->StartDate, 0, 10) !== $case['start']
            || (int) $class->SessionDuration !== 120
            || (int) $class->SessionCount !== $case['count']
            || (int) $class->UsedSessions !== $case['used']
            || (int) $class->RemainingSessions !== $case['remaining']
            || ($class->EndDate ? substr((string) $class->EndDate, 0, 10) : null) !== $case['end']) {
            throw new RuntimeException("Contract precondition drift: {$classId}");
        }
        $rows = ClassSession::query()->where('StudentClassID', $classId)
            ->whereDate('SessionDate', '>=', '2026-10-01')->lockForUpdate()->get()->keyBy('id');
        if ($rows->count() !== count($case['rows']) || array_diff(array_keys($case['rows']), $rows->keys()->all())) {
            throw new RuntimeException("Future occurrence set drift: {$classId}");
        }
        foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
            $row = $rows[$sessionId];
            $expectedEnd = \Carbon\Carbon::createFromFormat('H:i', $start)->addHours(2)->format('H:i');
            if (substr((string) $row->SessionDate, 0, 10) !== $date
                || substr((string) $row->StartTime, 0, 5) !== $start
                || substr((string) $row->EndTime, 0, 5) !== $expectedEnd
                || (string) $row->Status !== $status
                || (int) $row->IsContractException !== $exception) {
                throw new RuntimeException("Occurrence precondition drift: {$sessionId}");
            }
            if ($status !== 'scheduled') {
                continue;
            }
            $targetStart = $case['new'];
            if ($start !== $targetStart) {
                $locked = DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->exists()
                    || DB::table('LearningRecord')->where('ClassSessionID', $sessionId)
                        ->where('Status', 'approved')->exists();
                if ($locked) {
                    throw new RuntimeException("Occurrence became immutable: {$sessionId}");
                }
                $changeCount++;
            }
            if ($exception && $start === $targetStart) {
                $adoptedCount++;
            }
        }
    }

    if ($mode === 'apply') {
        foreach ($cases as $classId => $case) {
            if ($case['old'] !== $case['new']) {
                $class = StudentClass::findOrFail($classId);
                $class->time = $case['new'] . ':00';
                $class->save();
            }
            foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
                if ($status !== 'scheduled') {
                    continue;
                }
                if ($start !== $case['new'] || $exception) {
                    $row = ClassSession::findOrFail($sessionId);
                    $row->StartTime = $case['new'] . ':00';
                    $row->EndTime = \Carbon\Carbon::createFromFormat('H:i', $case['new'])->addHours(2)->format('H:i:s');
                    $row->IsContractException = 0;
                    $row->save();
                }
            }
        }
        foreach ($cases as $classId => $case) {
            if (substr((string) StudentClass::findOrFail($classId)->time, 0, 5) !== $case['new']) {
                throw new RuntimeException("Contract verification failed: {$classId}");
            }
            foreach ($case['rows'] as $sessionId => [$date, $start, $status]) {
                $row = ClassSession::findOrFail($sessionId);
                if ((string) $row->Status !== $status
                    || ($status === 'scheduled' && (substr((string) $row->StartTime, 0, 5) !== $case['new']
                        || (int) $row->IsContractException !== 0))) {
                    throw new RuntimeException("Occurrence verification failed: {$sessionId}");
                }
            }
        }
    }
    return ['mode' => $mode, 'class_ids' => $classIds, 'time_updates' => $changeCount,
        'matching_exceptions_adopted' => $adoptedCount, 'cancelled_rows_preserved' => 8];
}, 3);

echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (\Throwable $e) {
    $reason = $e instanceof \RuntimeException ? $e->getMessage() : 'Unexpected repair error';
    fwrite(STDERR, "::error::{$reason}" . PHP_EOL);
    exit(1);
}
