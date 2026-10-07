<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\BillingPeriodInvoiceExists;
use App\Services\Billing\InvoiceIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pins the unpaid-invoice shape every issuing site produced before InvoiceIssuer, and the opt-in period guard. */
class InvoiceIssuerTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'issuer@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function course(): StudentClass
    {
        $student = Student::create(['name' => '帳單學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-08-01', 'EndDate' => '2026-09-10', 'TotalHours' => 20, 'Charge' => 6000, 'Paid' => 0,
            'Rate' => 1500, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
            'settlement_day' => 15, 'monthly_sessions' => 4, 'week' => 3, 'time' => '18:00:00',
        ]);
    }

    public function test_issue_applies_unpaid_defaults_and_item_fallback_course(): void
    {
        $sc = $this->course();
        $invoice = app(InvoiceIssuer::class)->issue([
            'StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 6000, 'ScheduleModeAtIssue' => 'date', 'billing_period' => '2026-09',
        ], [['Description' => '月結', 'Amount' => 6000, 'PeriodStart' => '2026-09-01', 'PeriodEnd' => '2026-09-30']]);

        $fresh = Invoice::findOrFail($invoice->id);
        $this->assertSame('unpaid', $fresh->Status);
        $this->assertSame(0, (int) $fresh->PaidAmount);
        $this->assertSame('', (string) $fresh->Note);
        $this->assertNull($fresh->DueDate);
        $this->assertSame('date', $fresh->ScheduleModeAtIssue);
        $item = InvoiceItem::where('InvoiceID', $invoice->id)->sole();
        $this->assertSame($sc->ID, (int) $item->StudentClassID);
        $this->assertSame(6000, (int) $item->Amount);
    }

    public function test_period_guard_is_opt_in_and_ignores_voided(): void
    {
        $sc = $this->course();
        $attrs = ['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-09-01', 'TotalAmount' => 100, 'billing_period' => '2026-09'];
        $issuer = app(InvoiceIssuer::class);
        $first = $issuer->issue($attrs, [], true);

        $issuer->issue($attrs); // guard off: duplicate allowed, as in the callers that never had it
        $this->assertSame(2, Invoice::where('StudentClassID', $sc->ID)->count());

        Invoice::where('StudentClassID', $sc->ID)->update(['Status' => 'void']);
        $issuer->issue($attrs, [], true); // voided invoices do not block
        $this->assertSame(3, Invoice::where('StudentClassID', $sc->ID)->count());

        $this->expectException(BillingPeriodInvoiceExists::class);
        $issuer->issue($attrs, [], true);
    }

    public function test_store_endpoint_creates_unpaid_invoice_and_rejects_same_period_duplicate(): void
    {
        $sc = $this->course();
        $body = ['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-09-01', 'TotalAmount' => 6000,
            'billing_period' => '2026-09', 'Items' => [['Description' => '月結', 'Amount' => 6000]]];

        $this->withHeaders($this->headers)->postJson('/api/v1/invoices', $body)->assertCreated()
            ->assertJsonPath('Status', 'unpaid')->assertJsonPath('PaidAmount', 0)->assertJsonPath('ScheduleModeAtIssue', 'date');
        $this->assertSame(1, InvoiceItem::where('StudentClassID', $sc->ID)->count());

        $this->withHeaders($this->headers)->postJson('/api/v1/invoices', $body)->assertStatus(409)
            ->assertJsonPath('code', 'billing_period_invoice_exists');
        $this->assertSame(1, Invoice::where('StudentClassID', $sc->ID)->count());
    }
}
