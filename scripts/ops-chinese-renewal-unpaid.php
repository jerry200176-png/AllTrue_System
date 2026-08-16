<?php
declare(strict_types=1);

$sourceId = (int) getenv('SOURCE_CLASS');
$studentId = (int) getenv('STUDENT_ID');
$studentName = (string) getenv('STUDENT_NAME');
$sessions = (int) getenv('SESSIONS');
$startDate = (string) getenv('START_DATE');
$expectedCharge = (int) getenv('EXPECTED_CHARGE');

if ($sourceId <= 0 || $studentId <= 0 || $sessions !== 8 || $startDate !== '2026-08-19') {
    fwrite(STDERR, "PHP_SCOPE_MISMATCH\n");
    exit(1);
}

require '/home/admin/backend/vendor/autoload.php';
$app = require '/home/admin/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\StudentClassController;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

$source = StudentClass::query()->where('ID', $sourceId)->first();
if (!$source) {
    fwrite(STDERR, "SOURCE_NOT_FOUND\n");
    exit(1);
}
if ((int) $source->StudentID !== $studentId) {
    fwrite(STDERR, "STUDENT_MISMATCH\n");
    exit(1);
}
if ((string) ($source->ScheduleMode ?? '') !== 'count') {
    fwrite(STDERR, "NOT_COUNT_MODE\n");
    exit(1);
}

$student = Student::query()->find($studentId);
if (!$student || (string) $student->name !== $studentName) {
    fwrite(STDERR, "NAME_MISMATCH\n");
    exit(1);
}

$subject = Subject::query()->find($source->SubjectID);
$subjectName = (string) ($subject->Subject_Name ?? '');
if (!str_contains($subjectName, '國文') || str_contains($subjectName, '數學')) {
    fwrite(STDERR, "SUBJECT_GUARD_FAIL {$subjectName}\n");
    exit(1);
}

if ((int) $source->Charge !== $expectedCharge) {
    fwrite(STDERR, "CHARGE_DRIFT source={$source->Charge} expected={$expectedCharge}\n");
    exit(1);
}

$request = Request::create(
    "/api/v1/student-classes/{$sourceId}/purchase-batch",
    'POST',
    [
        'sessions' => $sessions,
        'start_date' => $startDate,
        'mode' => 'new_purchase',
    ]
);
$request->headers->set('Accept', 'application/json');
$request->attributes->set('auth_role', 'super_admin');
$app->instance('request', $request);
RequestFacade::swap($request);

$controller = $app->make(StudentClassController::class);
$response = $controller->purchaseBatch($request, $source);
$status = $response->getStatusCode();
$content = (string) $response->getContent();
echo "HTTP_STATUS={$status}\n";
echo $content, "\n";

if ($status === 409) {
    echo "IDEMPOTENT_DUPLICATE\n";
    exit(0);
}
if ($status !== 201) {
    fwrite(STDERR, "PURCHASE_BATCH_FAILED\n");
    exit(1);
}

$data = json_decode($content, true);
$newId = (int) ($data['new_course']['id'] ?? 0);
$new = StudentClass::query()->find($newId);
if (!$new) {
    fwrite(STDERR, "NEW_COURSE_MISSING\n");
    exit(1);
}
$paid = (int) ($new->Paid ?? 0);
$pay = (int) ($new->Pay ?? 0);
$count = (int) ($new->SessionCount ?? 0);
$charge = (int) ($new->Charge ?? 0);
if ($paid !== 0 || $pay !== 0 || $count !== 8 || $charge !== $expectedCharge) {
    fwrite(STDERR, "POSTCHECK_FAIL paid={$paid} pay={$pay} count={$count} charge={$charge}\n");
    exit(1);
}

$moved = \App\Models\ClassSession::query()
    ->whereIn('id', [23157, 27156])
    ->where('StudentClassID', $newId)
    ->count();
if ($moved > 0) {
    fwrite(STDERR, "TRANSFER_GUARD_FAIL 8/5 session landed on the new batch\n");
    exit(1);
}

echo "RENEWAL_CREATED id={$newId} paid=0 charge={$charge} sessions=8 start={$startDate}\n";
