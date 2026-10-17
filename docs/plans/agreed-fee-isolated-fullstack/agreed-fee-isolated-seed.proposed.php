<?php
/**
 * DRAFT — proposed path: backend/tests/Support/agreed-fee-isolated-seed.php
 *
 * Seeds one synthetic trial course (Charge 8000 + unpaid invoice) mirroring
 * AgreedFeeFixture. Writes /tmp/agreed-fee-isolated.env for Playwright.
 * Refuses non-AllTrue_test.
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

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$host = (string) config('database.connections.mysql.host');
$name = (string) config('database.connections.mysql.database');
if ($name !== 'AllTrue_test' || !in_array($host, ['127.0.0.1', 'localhost', 'agreed-fee-test-mysql'], true)) {
    fwrite(STDERR, "seed: refusing {$host}/{$name}\n");
    exit(90);
}

config(['billing.agreed_fee_correction_enabled' => true, 'database.default' => 'mysql']);
AgreedFeeTestSchema::ensureExtensionColumns();

$user = User::create([
    'LoginName' => 'fee-iso-'.Str::uuid(),
    'Name' => 'Isolated Director',
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

$env = '/tmp/agreed-fee-isolated.env';
file_put_contents($env, implode("\n", [
    'AGREED_FEE_ISOLATED_TOKEN='.$token,
    'AGREED_FEE_ISOLATED_COURSE_ID='.$course->ID,
    'AGREED_FEE_ISOLATED_INVOICE_ID='.$invoice->id,
    'AGREED_FEE_ISOLATED_API_URL=http://127.0.0.1:8000',
    'AGREED_FEE_ISOLATED_BASE_URL=http://127.0.0.1:5179',
])."\n");
fwrite(STDOUT, "wrote {$env}\n");
