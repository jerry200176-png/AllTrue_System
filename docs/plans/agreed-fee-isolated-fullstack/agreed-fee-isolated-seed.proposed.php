<?php
/**
 * DRAFT — proposed install path: backend/tests/Support/agreed-fee-isolated-seed.php
 *
 * Seeds one synthetic trial course using the **same field contract** as
 * Tests\Support\AgreedFeeFixture::fixture() (do not invent alternate shapes).
 * Writes /tmp/agreed-fee-isolated.env with mode 0600. Never prints the token.
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
require $backendRoot.'/vendor/autoload.php';
$app = require $backendRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

agreed_fee_isolated_assert_effective_connection();
agreed_fee_isolated_apply_runtime_config();
AgreedFeeTestSchema::ensureExtensionColumns();

// === AgreedFeeFixture::fixture() field contract (keep in sync; do not "improve") ===
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
$student = Student::create([
    'name' => 'Synthetic fee fixture',
    'CampusID' => 1,
    'ClassID' => 1,
    'enable' => 1,
    'MDT' => now(),
    'Notify_Token' => '',
]);
$course = StudentClass::create([
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
]);
$invoice = Invoice::create([
    'StudentID' => $student->id,
    'StudentClassID' => $course->ID,
    'IssueDate' => '2026-10-01',
    'DueDate' => '2026-10-20',
    'TotalAmount' => 7600,
    'PaidAmount' => 0,
    'Status' => 'unpaid',
    'Note' => 'Original retained note',
    'ScheduleModeAtIssue' => 'count',
]);
InvoiceItem::create([
    'InvoiceID' => $invoice->id,
    'StudentClassID' => $course->ID,
    'Description' => 'Original retained line',
    'Amount' => 7600,
]);

$envPath = '/tmp/agreed-fee-isolated.env';
$payload = implode("\n", [
    'AGREED_FEE_ISOLATED_TOKEN='.$token,
    'AGREED_FEE_ISOLATED_COURSE_ID='.$course->ID,
    'AGREED_FEE_ISOLATED_INVOICE_ID='.$invoice->id,
    'AGREED_FEE_ISOLATED_API_URL=http://127.0.0.1:8000',
    'AGREED_FEE_ISOLATED_BASE_URL=http://127.0.0.1:5179',
])."\n";
file_put_contents($envPath, $payload);
chmod($envPath, 0600);
// Short-lived: seed also writes an mtime stamp for cleanup (stack removes on exit).
touch($envPath);

fwrite(STDOUT, "seed: wrote {$envPath} mode 0600 (token redacted); course_id={$course->ID} invoice_id={$invoice->id}\n");
