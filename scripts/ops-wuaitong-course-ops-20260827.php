<?php

declare(strict_types=1);

/*
 * Exact one-off operation for Wu Ai-tong, 2026-08-27.
 *
 * The allowlist is deliberately embedded here as a second gate (the workflow
 * is not the only trust boundary).  The operation uses the same application
 * controller paths as the director UI: togglePause(reason=settled) for the
 * two paid monthly courses and addSession(auto_approve=false) for the class
 * occurrence.  A failed precondition throws before the outer transaction can
 * commit.  Re-running after success is idempotent for already-settled courses
 * and an already-existing exact occurrence.
 */

use App\Http\Controllers\StudentClassController;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require '/home/admin/backend/vendor/autoload.php';
$app = require '/home/admin/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$mode = getenv('MODE') ?: 'dry-run';
$runId = getenv('RUN_ID') ?: 'unknown';
$actor = getenv('ACTOR') ?: 'unknown';
$dryRunId = getenv('DRY_RUN_ID') ?: '';

$studentId = 178;
$campusId = 16;
$teacherId = 17;
$settleIds = [1040, 1390];
$addCourseId = 2688;
$targetDate = '2026-08-27';
$startTime = '17:00:00';
$endTime = '19:00:00';

if ($mode === 'execute' && !preg_match('/^[0-9]+$/', $dryRunId)) {
    throw new RuntimeException('execute requires a numeric approved dry-run ID');
}

$student = Student::query()->whereKey($studentId)->first();
if (!$student || $student->name !== '吳艾潼' || (int) $student->CampusID !== $campusId) {
    throw new RuntimeException('PRECONDITION_FAILED student allowlist');
}

$teacher = User::query()->whereKey($teacherId)->first();
if (!$teacher || $teacher->Name !== '鄭翔祐') {
    throw new RuntimeException('PRECONDITION_FAILED teacher allowlist');
}

$settleCourses = StudentClass::query()->whereIn('ID', $settleIds)->get()->keyBy('ID');
$addCourse = StudentClass::query()->whereKey($addCourseId)->first();
if ($settleCourses->count() !== count($settleIds) || !$addCourse) {
    throw new RuntimeException('PRECONDITION_FAILED course IDs not found');
}

foreach ($settleIds as $courseId) {
    $course = $settleCourses->get($courseId);
    if ((int) $course->StudentID !== $studentId
        || (int) $course->Paid !== 1
        || (string) $course->ScheduleMode !== 'date'
        || (int) $course->PackageID > 0
    ) {
        throw new RuntimeException("PRECONDITION_FAILED settle course {$courseId}");
    }
}

if ((int) $addCourse->StudentID !== $studentId
    || (int) $addCourse->TeacherID !== $teacherId
    || (string) $addCourse->ScheduleMode !== 'date'
    || (int) $addCourse->PackageID > 0
) {
    throw new RuntimeException('PRECONDITION_FAILED add course 2688');
}

$existing = ClassSession::query()
    ->where('StudentClassID', $addCourseId)
    ->whereDate('SessionDate', $targetDate)
    ->where('StartTime', $startTime)
    ->whereNotIn('Status', ['cancelled', 'voided'])
    ->orderBy('id')
    ->first();

$before = [
    'student' => ['id' => $student->id, 'name' => $student->name, 'campus_id' => (int) $student->CampusID],
    'teacher' => ['id' => $teacher->id, 'name' => $teacher->Name],
    'settle_courses' => collect($settleIds)->mapWithKeys(function (int $id) use ($settleCourses): array {
        $c = $settleCourses->get($id);
        return [$id => [
            'paid' => (int) $c->Paid,
            'stop' => (int) $c->Stop,
            'closed_reason' => $c->closed_reason,
            'schedule_mode' => $c->ScheduleMode,
            'used_sessions' => (int) ($c->UsedSessions ?? 0),
            'remaining_sessions' => (int) ($c->RemainingSessions ?? 0),
        ]];
    })->all(),
    'add_course' => [
        'id' => (int) $addCourse->ID,
        'teacher_id' => (int) $addCourse->TeacherID,
        'schedule_mode' => $addCourse->ScheduleMode,
        'start_date' => $addCourse->StartDate,
        'end_date' => $addCourse->EndDate,
    ],
    'existing_target_session' => $existing ? [
        'id' => (int) $existing->id,
        'status' => $existing->Status,
        'end_time' => substr((string) $existing->EndTime, 0, 8),
    ] : null,
];

echo 'SCOPE_OK ' . json_encode([
    'student_id' => $studentId,
    'campus_id' => $campusId,
    'teacher_id' => $teacherId,
    'settle_course_ids' => $settleIds,
    'add_course_id' => $addCourseId,
    'target' => "$targetDate 17:00-19:00",
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'BEFORE ' . json_encode($before, JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($mode === 'dry-run') {
    echo 'DRY_RUN_ONLY no production write' . PHP_EOL;
    exit(0);
}

if ($existing && !in_array(strtolower((string) $existing->Status), ['completed', 'attended', 'late'], true)) {
    throw new RuntimeException('PRECONDITION_FAILED exact target slot exists but is not completed/attended; refusing to overwrite');
}

$result = DB::transaction(function () use (
    $settleIds,
    $addCourseId,
    $targetDate,
    $startTime,
    $endTime,
    $existing,
    $runId,
    $actor
): array {
    $controller = app(StudentClassController::class);

    $authRequest = Request::create('/api/v1/student-classes/operation', 'POST');
    $authRequest->attributes->set('auth_role', 'super_admin');
    $authRequest->attributes->set('auth_campus_ids', []);
    $authRequest->attributes->set('auth_user', User::find(4));
    app()->instance('request', $authRequest);

    $settled = [];
    foreach ($settleIds as $courseId) {
        $course = StudentClass::query()->whereKey($courseId)->lockForUpdate()->firstOrFail();
        if ((int) $course->Stop === 1 && (string) $course->closed_reason === 'settled') {
            $settled[$courseId] = ['already_settled' => true, 'cancelled_count' => 0];
            continue;
        }

        $request = Request::create('/api/v1/student-classes/' . $courseId . '/pause', 'POST', [
            'action' => 'pause',
            'reason' => 'settled',
            'cancel_remaining' => true,
            'forfeit_remaining' => true,
        ]);
        $request->attributes->set('auth_role', 'super_admin');
        $request->attributes->set('auth_campus_ids', []);
        $request->attributes->set('auth_user', User::find(4));
        app()->instance('request', $request);
        $response = $controller->togglePause($request, $course);
        $payload = method_exists($response, 'getData') ? $response->getData(true) : [];
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('SETTLE_FAILED course ' . $courseId . ': ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        $settled[$courseId] = $payload;
    }

    $course = StudentClass::query()->whereKey($addCourseId)->lockForUpdate()->firstOrFail();
    $current = ClassSession::query()
        ->where('StudentClassID', $addCourseId)
        ->whereDate('SessionDate', $targetDate)
        ->where('StartTime', $startTime)
        ->whereNotIn('Status', ['cancelled', 'voided'])
        ->orderBy('id')
        ->first();

    if ($current && in_array(strtolower((string) $current->Status), ['completed', 'attended', 'late'], true)) {
        $added = ['already_exists' => true, 'class_session_id' => (int) $current->id, 'status' => $current->Status];
    } else {
        $request = Request::create('/api/v1/student-classes/' . $addCourseId . '/add-session', 'POST', [
            'session_date' => $targetDate,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'teacher_id' => 17,
            'note' => '主任補登：2026-08-27 17:00-19:00',
            // The class is added, but attendance/evaluation must not be
            // fabricated by an automated repair.
            'auto_approve' => false,
        ]);
        $request->attributes->set('auth_role', 'super_admin');
        $request->attributes->set('auth_campus_ids', []);
        $request->attributes->set('auth_user', User::find(4));
        app()->instance('request', $request);
        $response = $controller->addSession($request, $course);
        $payload = method_exists($response, 'getData') ? $response->getData(true) : [];
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('ADD_SESSION_FAILED: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        $added = $payload;
    }

    return ['settled' => $settled, 'added' => $added, 'run_id' => $runId, 'actor' => $actor];
});

foreach ($settleIds as $courseId) {
    $after = StudentClass::query()->whereKey($courseId)->firstOrFail();
    if ((int) $after->Stop !== 1 || (string) $after->closed_reason !== 'settled' || (int) $after->Paid !== 1) {
        throw new RuntimeException("POSTCONDITION_FAILED settle course {$courseId}");
    }
}

$afterSession = ClassSession::query()
    ->where('StudentClassID', $addCourseId)
    ->whereDate('SessionDate', $targetDate)
    ->where('StartTime', $startTime)
    ->where('EndTime', $endTime)
    ->whereNotIn('Status', ['cancelled', 'voided'])
    ->orderBy('id')
    ->first();
if (!$afterSession) {
    throw new RuntimeException('POSTCONDITION_FAILED target session missing');
}

echo 'RESULT ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'AFTER ' . json_encode([
    'settled' => collect($settleIds)->mapWithKeys(function (int $id): array {
        $c = StudentClass::query()->whereKey($id)->firstOrFail();
        return [$id => ['paid' => (int) $c->Paid, 'stop' => (int) $c->Stop, 'closed_reason' => $c->closed_reason, 'end_date' => $c->EndDate]];
    })->all(),
    'target_session' => ['id' => (int) $afterSession->id, 'status' => $afterSession->Status, 'date' => $afterSession->SessionDate, 'start' => $afterSession->StartTime, 'end' => $afterSession->EndTime],
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'WU_AITONG_OPERATION_OK' . PHP_EOL;
