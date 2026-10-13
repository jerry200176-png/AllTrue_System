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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #3894 — real API payloads for every 繳費單／收據 state must carry the fields the buttons read (fixture shared with the frontend contract test). */
class SlipReceiptStateContractTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../Fixtures/slip_receipt_states_3894.json';

    private array $director;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-28 10:00:00');
        $this->director = $this->headersFor('director-3894@example.com', 'A');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_real_alert_rows_carry_exactly_the_fields_the_button_logic_reads(): void
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true);
        $courses = $this->seedStates();
        $rows = collect($this->getJson('/api/v1/alerts/tuition?branch_id=1', $this->director)->assertOk()->json())->keyBy('id');

        foreach ($fixture['states'] as $name => $state) {
            if ($name === 'no_permission') {
                continue; // same row as `unpaid`; denial asserted below
            }
            $row = $rows->get($courses[$name]->ID);
            $this->assertNotNull($row, "$name: course is listed by alerts/tuition");
            foreach ($fixture['fields_read'] as $field) {
                $this->assertArrayHasKey($field, $row, "$name: backend row must still expose `$field`");
            }
            $this->assertEquals($state['row'], array_intersect_key($row, array_flip($fixture['fields_read'])), "$name: real payload == fixture inputs");
        }
    }

    public function test_receipt_lookup_returns_a_confirmed_report_even_without_invoice_but_not_for_legacy_or_unpaid(): void
    {
        $courses = $this->seedStates();
        foreach ($courses as $name => $course) {
            $data = $this->getJson("/api/v1/payment-reports?student_class_id={$course->ID}&status=confirmed&branch_id=1", $this->director)
                ->assertOk()->json('data');
            if (in_array($name, ['paid_with_invoice', 'paid_confirmed_report_no_invoice', 'partial_confirmed'], true)) {
                $this->assertNotEmpty($data, 'receipt button has a confirmed report to open');
                $this->assertArrayHasKey('id', $data[0]);
                $this->getJson("/api/v1/payment-reports/{$data[0]['id']}/receipt", $this->director)->assertOk();
            } else {
                $this->assertSame([], $data, "$name: no confirmed report, so no receipt");
            }
        }
    }

    public function test_ledger_scope_and_course_flags_distinguish_legacy_paid_from_invoiced(): void
    {
        $courses = $this->seedStates();
        $before = [Invoice::count(), Payment::count(), PaymentReport::count()];
        foreach (['unpaid', 'no_invoice_unpaid'] as $name) {
            $this->getJson("/api/v1/alerts/tuition-slip/{$courses[$name]->ID}", $this->director)->assertOk();
        }
        foreach (['legacy_paid_no_invoice' => [true, 0], 'paid_with_invoice' => [true, 1], 'unpaid' => [false, 1]] as $name => [$paidFlag, $invoiceCount]) {
            $id = (int) $courses[$name]->ID;
            $ledger = $this->getJson("/api/v1/accounting/ledger?student_class_id=$id", $this->director)->assertOk()->json();
            $this->assertSame($id, $ledger['scope']['student_class_id'], "$name: ledger scope names the opened course");
            $course = collect($ledger['courses'])->firstWhere('id', $id);
            $this->assertSame($paidFlag, $course['paid'], "$name: ContractCard reads courses[].paid");
            $mine = collect($ledger['invoices'])->where('student_class_id', $id);
            $this->assertCount($invoiceCount, $mine, "$name: invoices carry student_class_id (其他課程 label + amounts)");
        }
        $this->assertSame($before, [Invoice::count(), Payment::count(), PaymentReport::count()], 'slip/ledger reads create no money rows');
    }

    public function test_non_director_is_denied_every_endpoint_behind_the_buttons(): void
    {
        $courses = $this->seedStates();
        $teacher = $this->headersFor('teacher-3894@example.com', 'T');
        $id = $courses['paid_with_invoice']->ID;
        $reportId = PaymentReport::where('StudentClassID', $id)->value('id');
        $this->getJson('/api/v1/alerts/tuition?branch_id=1', $teacher)->assertForbidden();
        $this->getJson("/api/v1/alerts/tuition-slip/{$courses['unpaid']->ID}", $teacher)->assertForbidden();
        $this->getJson("/api/v1/payment-reports?student_class_id=$id&status=confirmed&branch_id=1", $teacher)->assertForbidden();
        $this->getJson("/api/v1/payment-reports/$reportId/receipt", $teacher)->assertForbidden();
        $this->getJson("/api/v1/accounting/ledger?student_class_id=$id", $teacher)->assertForbidden();
    }

    /** @return array<string, StudentClass> */
    private function seedStates(): array
    {
        $c = ['unpaid' => $this->course([]), 'partial' => $this->course([]), 'reported_pending' => $this->course([]), 'no_invoice_unpaid' => $this->course([]),
            'paid_with_invoice' => $this->course(['Paid' => 1, 'RemainingSessions' => 0]), 'paid_confirmed_report_no_invoice' => $this->course(['Paid' => 1, 'RemainingSessions' => 0]), 'legacy_paid_no_invoice' => $this->course(['Paid' => 1, 'RemainingSessions' => 0]), 'partial_confirmed' => $this->course([])];
        $this->invoice($c['unpaid'], 0, 'unpaid');
        $partial = $this->invoice($c['partial'], 2000, 'partial');
        Payment::create(['InvoiceID' => $partial->id, 'Amount' => 2000, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);
        $this->report($c['reported_pending'], $this->invoice($c['reported_pending'], 0, 'unpaid'), 'pending', null);
        $paid = $this->invoice($c['paid_with_invoice'], 5000, 'paid');
        $payment = Payment::create(['InvoiceID' => $paid->id, 'Amount' => 5000, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);
        $this->report($c['paid_with_invoice'], $paid, 'confirmed', $payment->id);
        // #3942: a confirmed report that covers only part of the bill leaves the invoice `partial` but has a receipt
        $partialConfirmed = $this->invoice($c['partial_confirmed'], 2000, 'partial');
        $this->report($c['partial_confirmed'], $partialConfirmed, 'confirmed', Payment::create(['InvoiceID' => $partialConfirmed->id, 'Amount' => 2000, 'PaidAt' => '2026-04-10', 'Method' => 'cash'])->id);
        // P1: a confirmed receipt whose invoice is missing (ledger anomaly receipt_without_invoice) must stay openable
        $this->report($c['paid_confirmed_report_no_invoice'], $paid, 'confirmed', null)->update(['InvoiceID' => null]);

        return $c;
    }

    private function course(array $overrides): StudentClass
    {
        $student = Student::create(['name' => '合成學生'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678',
            'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }

    private function invoice(StudentClass $sc, int $paid, string $status): Invoice
    {
        return Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01', 'TotalAmount' => 5000, 'PaidAmount' => $paid, 'Status' => $status]);
    }

    private function report(StudentClass $sc, Invoice $invoice, string $status, ?int $paymentId): PaymentReport
    {
        return PaymentReport::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice->id, 'reported_by_name' => '合成',
            'payment_date' => '2026-04-10', 'payment_method' => 'cash', 'reported_amount' => 5000, 'status' => $status, 'payment_id' => $paymentId,
            'report_token_hash' => hash('sha256', "3894-$status-{$sc->ID}"), 'token_expires_at' => Carbon::now()->addDay()]);
    }

    private function headersFor(string $login, string $type): array
    {
        $user = User::create(['LoginName' => $login, 'Name' => '合成使用者', 'PSW' => 'secret', 'type' => $type, 'phone' => random_int(900000000, 999999999)]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }
}
