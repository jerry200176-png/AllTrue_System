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

/**
 * #3894 — backend-connected acceptance for the 繳費單／收據 buttons on 帳務中心.
 * Synthetic rows for every state go through the REAL endpoints the page reads (alerts/tuition, payment-reports,
 * accounting/ledger). The fields the button logic reads and the shared fixture's inputs must match the real payload, so
 * renaming/removing a backend field fails here; the component outcome for those inputs is pinned by
 * frontend SlipReceiptStateContract.test.js against the same fixture.
 */
class SlipReceiptStateContractTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../Fixtures/slip_receipt_states_3894.json';

    private array $director;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-28 10:00:00');
        $this->director = $this->headers($this->user('director-3894@example.com', 'A', true));
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
                continue; // same row as `unpaid`; the denial is asserted below
            }
            $row = $rows->get($courses[$name]->ID);
            $this->assertNotNull($row, "$name: course is listed by alerts/tuition");
            foreach ($fixture['fields_read'] as $field) {
                $this->assertArrayHasKey($field, $row, "$name: backend row must still expose `$field`");
            }
            $actual = array_intersect_key($row, array_flip($fixture['fields_read']));
            $expected = $state['row'];
            ksort($actual);
            ksort($expected);
            $this->assertSame($expected, $actual, "$name: real payload == fixture inputs");
        }
    }

    public function test_receipt_lookup_returns_a_confirmed_report_only_for_paid_with_invoice(): void
    {
        $courses = $this->seedStates();
        foreach ($courses as $name => $course) {
            $data = $this->getJson("/api/v1/payment-reports?student_class_id={$course->ID}&status=confirmed&branch_id=1", $this->director)
                ->assertOk()->json('data');
            if ($name === 'paid_with_invoice') {
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
        foreach (['legacy_paid_no_invoice' => [true, 0], 'paid_with_invoice' => [true, 1], 'unpaid' => [false, 1]] as $name => [$paidFlag, $invoiceCount]) {
            $id = (int) $courses[$name]->ID;
            $ledger = $this->getJson("/api/v1/accounting/ledger?student_class_id=$id", $this->director)->assertOk()->json();
            $this->assertSame($id, $ledger['scope']['student_class_id'], "$name: ledger scope names the opened course");
            $course = collect($ledger['courses'])->firstWhere('id', $id);
            $this->assertSame($paidFlag, $course['paid'], "$name: ContractCard reads courses[].paid");
            $mine = collect($ledger['invoices'])->where('student_class_id', $id);
            $this->assertCount($invoiceCount, $mine, "$name: invoices carry student_class_id (其他課程 label + amounts)");
        }
    }

    public function test_non_director_is_denied_every_endpoint_behind_the_buttons(): void
    {
        $courses = $this->seedStates();
        $teacher = $this->headers($this->user('teacher-3894@example.com', 'T', true));
        $id = $courses['paid_with_invoice']->ID;
        $reportId = PaymentReport::where('StudentClassID', $id)->value('id');
        $this->getJson('/api/v1/alerts/tuition?branch_id=1', $teacher)->assertForbidden();
        $this->getJson("/api/v1/alerts/tuition-slip/{$courses['unpaid']->ID}", $teacher)->assertForbidden();
        $this->getJson("/api/v1/payment-reports?student_class_id=$id&status=confirmed&branch_id=1", $teacher)->assertForbidden();
        $this->getJson("/api/v1/payment-reports/$reportId/receipt", $teacher)->assertForbidden();
        $this->getJson("/api/v1/accounting/ledger?student_class_id=$id", $teacher)->assertForbidden();
    }

    public function test_read_only_buttons_do_not_create_money_rows(): void
    {
        $courses = $this->seedStates();
        $before = [Invoice::count(), Payment::count(), PaymentReport::count()];
        $this->getJson("/api/v1/alerts/tuition-slip/{$courses['unpaid']->ID}", $this->director)->assertOk();
        $this->getJson("/api/v1/alerts/tuition-slip/{$courses['no_invoice_unpaid']->ID}", $this->director)->assertOk();
        $this->getJson("/api/v1/accounting/ledger?student_class_id={$courses['legacy_paid_no_invoice']->ID}", $this->director)->assertOk();
        $this->assertSame($before, [Invoice::count(), Payment::count(), PaymentReport::count()]);
    }

    /** @return array<string, StudentClass> */
    private function seedStates(): array
    {
        $c = [];
        $c['unpaid'] = $this->course(['Charge' => 5000]);
        $this->invoice($c['unpaid'], 5000, 0, 'unpaid');

        $c['partial'] = $this->course(['Charge' => 5000]);
        $partial = $this->invoice($c['partial'], 5000, 2000, 'partial');
        Payment::create(['InvoiceID' => $partial->id, 'Amount' => 2000, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);

        $c['reported_pending'] = $this->course(['Charge' => 5000]);
        $pending = $this->invoice($c['reported_pending'], 5000, 0, 'unpaid');
        $this->report($c['reported_pending'], $pending, 5000, 'pending', 'pending-3894', null);

        $c['paid_with_invoice'] = $this->course(['Charge' => 5000, 'Paid' => 1, 'RemainingSessions' => 0]);
        $paid = $this->invoice($c['paid_with_invoice'], 5000, 5000, 'paid');
        $payment = Payment::create(['InvoiceID' => $paid->id, 'Amount' => 5000, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);
        $this->report($c['paid_with_invoice'], $paid, 5000, 'confirmed', 'confirmed-3894', $payment->id);

        $c['no_invoice_unpaid'] = $this->course(['Charge' => 5000]);

        $c['legacy_paid_no_invoice'] = $this->course(['Charge' => 5000, 'Paid' => 1, 'RemainingSessions' => 0]);

        return $c;
    }

    private function course(array $overrides): StudentClass
    {
        $student = Student::create(['name' => '合成學生'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678',
            'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }

    private function invoice(StudentClass $sc, int $total, int $paid, string $status): Invoice
    {
        return Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $status]);
    }

    private function report(StudentClass $sc, Invoice $invoice, int $amount, string $status, string $tag, ?int $paymentId): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => '合成', 'payment_date' => '2026-04-10', 'payment_method' => 'cash',
            'reported_amount' => $amount, 'status' => $status, 'payment_id' => $paymentId,
            'report_token_hash' => hash('sha256', $tag), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    private function user(string $login, string $type, bool $campus): User
    {
        $user = User::create(['LoginName' => $login, 'Name' => '合成使用者', 'PSW' => 'secret', 'type' => $type, 'phone' => random_int(900000000, 999999999)]);
        if ($campus) {
            UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }

        return $user;
    }

    private function headers(User $user): array
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }
}
