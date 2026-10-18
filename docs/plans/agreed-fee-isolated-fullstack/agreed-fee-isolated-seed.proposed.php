<?php
/**
 * DRAFT — install as: backend/tests/Support/agreed-fee-isolated-seed.php
 *
 * Primary trial fixture = AgreedFeeFixture::fixture() field contract (unchanged).
 * Additional synthetic courses are post-create updates (same shapes as PHPUnit cases).
 * Env file written with umask 077 → mode 0600. Token never printed.
 */
declare(strict_types=1);

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeTestSchema;

require __DIR__.'/agreed-fee-isolated-db-guard.php';
agreed_fee_isolated_assert_db();

$backendRoot = dirname(__DIR__, 2);
$configCache = $backendRoot.'/bootstrap/cache/config.php';
if (is_file($configCache)) {
    agreed_fee_isolated_refuse('bootstrap/cache/config.php present — clear before isolated seed');
}

$app = agreed_fee_isolated_boot_application($backendRoot);
AgreedFeeTestSchema::ensureExtensionColumns();

$user = User::create([
    'LoginName' => 'fee-'.Str::uuid(),
    'Name' => 'Fixture',
    'PSW' => 'test',
    'type' => 'A',
    'phone' => '',
]);
UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
$token = (string) Str::uuid();
AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

$mkCourse = static function (array $overrides = []) use ($user): array {
    $student = Student::create([
        'name' => 'Synthetic fee fixture',
        'CampusID' => 1,
        'ClassID' => 1,
        'enable' => 1,
        'MDT' => now(),
        'Notify_Token' => '',
    ]);
    $course = StudentClass::create(array_merge([
        'StudentID' => $student->id,
        'TeacherID' => 1,
        'GradeID' => 1,
        'SubjectID' => 1,
        'by1' => 1,
        'Period' => 4,
        'StartDate' => '2026-10-01',
        'EndDate' => '2026-12-31',
        'TotalHours' => 16,
        'Charge' => 8000,
        'Rate' => 1000,
        'Paid' => 0,
        'MDate' => now(),
        'Stop' => 0,
        'ScheduleMode' => 'count',
        'SessionCount' => 8,
        'RemainingSessions' => 5,
        'UsedSessions' => 3,
        'SessionDuration' => 120,
        'ClassType' => 'trial',
    ], $overrides['course'] ?? []));
    $invoiceTotal = $overrides['invoice_total'] ?? 7600;
    $invoice = Invoice::create([
        'StudentID' => $student->id,
        'StudentClassID' => $course->ID,
        'IssueDate' => '2026-10-01',
        'DueDate' => '2026-10-20',
        'TotalAmount' => $invoiceTotal,
        'PaidAmount' => 0,
        'Status' => 'unpaid',
        'Note' => 'Original retained note',
        'ScheduleModeAtIssue' => 'count',
    ]);
    InvoiceItem::create([
        'InvoiceID' => $invoice->id,
        'StudentClassID' => $course->ID,
        'Description' => 'Original retained line',
        'Amount' => $invoiceTotal,
    ]);

    return compact('student', 'course', 'invoice');
};

// A) trial 8000→500 (AgreedFeeFixture contract)
$a = $mkCourse();
// B) invoice-only discrepancy Charge 7200 / invoice 8800 → new_charge 7200
$b = $mkCourse(['course' => ['Charge' => 7200], 'invoice_total' => 8800]);
// C) regular one_on_one 8800→7200
$c = $mkCourse([
    'course' => [
        'ClassType' => 'one_on_one',
        'Charge' => 8800,
        'Rate' => 1100,
        'UsedSessions' => 5,
        'RemainingSessions' => 3,
    ],
    'invoice_total' => 8800,
]);
// D) state_changed probe (never apply successfully in that test)
$d = $mkCourse();

$cases = [
    'trial_8000_500' => [
        'course_id' => $a['course']->ID,
        'invoice_id' => $a['invoice']->id,
        'new_charge' => 500,
        'expect_old_charge' => 8000,
        'expect_invoice_total' => 7600,
    ],
    'invoice_8800_to_7200' => [
        'course_id' => $b['course']->ID,
        'invoice_id' => $b['invoice']->id,
        'new_charge' => 7200,
        'expect_old_charge' => 7200,
        'expect_invoice_total' => 8800,
    ],
    'regular_8800_to_7200' => [
        'course_id' => $c['course']->ID,
        'invoice_id' => $c['invoice']->id,
        'new_charge' => 7200,
        'expect_old_charge' => 8800,
        'expect_invoice_total' => 8800,
    ],
    'state_probe' => [
        'course_id' => $d['course']->ID,
        'invoice_id' => $d['invoice']->id,
        'new_charge' => 500,
        'expect_old_charge' => 8000,
        'expect_invoice_total' => 7600,
    ],
];

$prev = umask(0077);
$envPath = '/tmp/agreed-fee-isolated.env';
$allowPath = '/tmp/agreed-fee-isolated-courses.json';
$lines = [
    'AGREED_FEE_ISOLATED_TOKEN='.$token,
    'AGREED_FEE_ISOLATED_COURSE_ID='.$a['course']->ID,
    'AGREED_FEE_ISOLATED_INVOICE_ID='.$a['invoice']->id,
    'AGREED_FEE_ISOLATED_API_URL=http://127.0.0.1:8000',
    'AGREED_FEE_ISOLATED_BASE_URL=http://127.0.0.1:5179',
    'AGREED_FEE_ISOLATED_CASE_TRIAL_COURSE_ID='.$a['course']->ID,
    'AGREED_FEE_ISOLATED_CASE_TRIAL_INVOICE_ID='.$a['invoice']->id,
    'AGREED_FEE_ISOLATED_CASE_INV8800_COURSE_ID='.$b['course']->ID,
    'AGREED_FEE_ISOLATED_CASE_INV8800_INVOICE_ID='.$b['invoice']->id,
    'AGREED_FEE_ISOLATED_CASE_REG8800_COURSE_ID='.$c['course']->ID,
    'AGREED_FEE_ISOLATED_CASE_REG8800_INVOICE_ID='.$c['invoice']->id,
    'AGREED_FEE_ISOLATED_CASE_STATE_COURSE_ID='.$d['course']->ID,
    'AGREED_FEE_ISOLATED_CASE_STATE_INVOICE_ID='.$d['invoice']->id,
];
file_put_contents($envPath, implode("\n", $lines)."\n");
chmod($envPath, 0600);
file_put_contents($allowPath, json_encode([
    'user_id' => $user->id,
    'token_expires_at' => now()->addDay()->toIso8601String(),
    'course_ids' => array_values(array_map(static fn ($c) => $c['course_id'], $cases)),
    'cases' => $cases,
], JSON_THROW_ON_ERROR));
chmod($allowPath, 0600);
umask($prev);

fwrite(STDOUT, 'seed: wrote env+allowlist mode 0600 (token redacted); courses='
    .implode(',', array_map('strval', array_column(array_values($cases), 'course_id')))."\n");
