<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #5721: the 帳務中心 row's waive_block is the waive endpoint's own refusal (WaiveGate), word for word. */
class WaiveGateAgreesTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'wg-' . uniqid() . '@example.com', 'Name' => '帳務', 'PSW' => 'secret', 'type' => 'D', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addHour()]);
        $this->headers = ['Authorization' => "Bearer {$token}"];
    }

    private function course(array $o = []): StudentClass
    {
        $student = Student::create(['name' => '不收測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
            'Stop' => 1, 'closed_reason' => 'settled_pending', 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 4, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $o));
    }

    private function invoice(StudentClass $c, int $total, int $paid = 0): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-09-01',
            'DueDate' => '2026-09-10', 'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $paid > 0 ? 'partial' : 'unpaid']);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-09-05', 'Method' => 'cash']);
        }

        return $invoice;
    }

    private function tuitionRow(StudentClass $c): array
    {
        $row = collect($this->getJson('/api/v1/alerts/tuition?branch_id=1', $this->headers)->assertOk()->json())
            ->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $c->ID);
        $this->assertNotNull($row, 'fixture must be a stopped-and-owing row in the tuition list');
        $this->assertSame('pending_reconciliation', $row['alert_type']);

        return $row;
    }

    private function assertRefusedTheSameWay(StudentClass $c, string $message): void
    {
        $row = $this->tuitionRow($c);
        $this->assertSame($message, $row['waive_block']);
        $invoices = Invoice::query()->where('StudentClassID', $c->ID)->pluck('Status', 'id')->all();

        $this->postJson("/api/v1/accounting/courses/{$c->ID}/waive", ['reason' => '不收了', 'expected_amount' => (int) $row['outstanding']], $this->headers)
            ->assertStatus(422)->assertJsonPath('message', $row['waive_block']);

        $this->assertSame('settled_pending', $c->fresh()->closed_reason);
        $this->assertSame($invoices, Invoice::query()->where('StudentClassID', $c->ID)->pluck('Status', 'id')->all());
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'accounting.course_waived')->count());
    }

    public function test_package_member(): void
    {
        $c = $this->course(['PackageID' => 1]);
        $this->invoice($c, 8800);
        $this->assertRefusedTheSameWay($c, '套裝課程請到套裝處理');
    }

    public function test_has_payment(): void
    {
        $c = $this->course();
        $this->invoice($c, 8800, 3000);
        $this->assertRefusedTheSameWay($c, '此合約已有收款紀錄，請先到帳務更正後再處理');
    }

    public function test_shared_bill(): void
    {
        $other = $this->course(['Stop' => 0, 'closed_reason' => null, 'Charge' => 1000]);
        $c = $this->course(['Charge' => 500]);
        $invoice = $this->invoice($c, 1500);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $invoice->id, 'StudentClassID' => $other->ID, 'Description' => 'other', 'Amount' => 1000]);
        $this->assertRefusedTheSameWay($c, '此合約在合併帳單中，請先到帳務處理該帳單');
    }

    public function test_pending_report(): void
    {
        $c = $this->course(['Charge' => 5000]);
        $this->invoice($c, 5000);
        PaymentReport::create([
            'StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'reported_by_name' => '家長',
            'payment_date' => '2026-09-02', 'payment_method' => 'cash', 'reported_amount' => 5000,
            'status' => 'pending', 'report_token_hash' => hash('sha256', 'wg-' . uniqid()), 'token_expires_at' => now()->addDay(),
        ]);
        $this->assertRefusedTheSameWay($c, '有待確認的繳費回報，請先確認或退回');
    }

    public function test_plain_settled_pending_without_payments_is_not_blocked_and_waives(): void
    {
        $c = $this->course();
        $invoice = $this->invoice($c, 8800);

        $row = $this->tuitionRow($c);
        $this->assertSame('pending_reconciliation', $row['payment_status']);
        $this->assertNull($row['waive_block']);

        $this->postJson("/api/v1/accounting/courses/{$c->ID}/waive", ['reason' => '不收了', 'expected_amount' => 8800], $this->headers)->assertOk();
        $this->assertSame('waived', $c->fresh()->closed_reason);
        $this->assertSame('void', $invoice->fresh()->Status);
    }
}
