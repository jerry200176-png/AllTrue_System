<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
 * M1 (docs/plans/2026-10-06-director-billing-recon-IMPL_HANDOFF_M1.md, FR-003):
 * 學生帳務對帳 expands an invoice / confirmed receipt by reading the same slip and
 * receipt endpoints the printed documents use. This pins the count-mode source
 * contract the expansion depends on (the monthly service-period list is pinned
 * by MonthlyBillingSlipTest::test_paid_monthly_slip_lists_held_and_upcoming_lessons)
 * and the campus isolation of both endpoints.
 */
class DirectorLedgerCoverageDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_count_mode_invoice_slip_lists_every_lesson_not_only_the_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Taipei'));
        $token = $this->directorToken([1]);
        [$student, $course] = $this->countCourse(1);
        $invoice = $this->invoiceFor($student, $course);

        $dates = $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertOk()
            ->json('sessions.*.date');

        // Cancelled 9/15 is not a covered lesson; everything else is listed in order.
        $this->assertSame(['2026-09-01', '2026-09-08', '2026-09-22', '2026-09-29', '2026-10-06'], $dates);
    }

    public function test_count_mode_receipt_lists_held_and_expected_lessons_up_to_purchased(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Taipei'));
        $token = $this->directorToken([1]);
        [$student, $course] = $this->countCourse(1);
        $report = $this->confirmedReport($student, $course);

        $rows = $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/payment-reports/{$report->id}/receipt")
            ->assertOk()
            ->json('session_dates');

        $this->assertSame(
            [
                ['date' => '2026/09/01', 'expected' => false],
                ['date' => '2026/09/08', 'expected' => false],
                ['date' => '2026/09/22', 'expected' => true],
                ['date' => '2026/09/29', 'expected' => true],
            ],
            array_map(fn (array $r) => ['date' => $r['date'], 'expected' => $r['expected']], $rows)
        );
    }

    public function test_other_campus_director_cannot_read_coverage_sources(): void
    {
        $outsider = $this->directorToken([2]);
        [$student, $course] = $this->countCourse(1);
        $invoice = $this->invoiceFor($student, $course);
        $report = $this->confirmedReport($student, $course);

        $this->withHeaders($this->headers($outsider))
            ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertForbidden();
        $this->withHeaders($this->headers($outsider))
            ->getJson("/api/v1/payment-reports/{$report->id}/receipt")
            ->assertForbidden();
    }

    public function test_contract_sessions_tag_each_lesson_with_payment_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00', 'Asia/Taipei'));
        $token = $this->directorToken([1]);
        [$student, $course] = $this->countCourse(1);
        $course->update(['Memo' => '家長要求週二上課', 'SessionCount' => 8]);
        $url = "/api/v1/accounting/contracts/{$course->ID}/sessions";

        $noBill = $this->withHeaders($this->headers($token))->getJson($url)->assertOk();
        $this->assertSame(['2026-09-01', '2026-09-08', '2026-09-22', '2026-09-29', '2026-10-06'], $noBill->json('sessions.*.date'));
        $this->assertSame(['no_invoice'], array_values(array_unique($noBill->json('sessions.*.payment'))));
        $this->assertSame('家長要求週二上課', $noBill->json('memo'));
        $this->assertSame(3, $noBill->json('unscheduled_count'));
        $this->assertSame('2026-09-01', $noBill->json('start_date'));
        $this->assertSame('count', $noBill->json('schedule_mode'));

        $invoice = $this->invoiceFor($student, $course);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 3000, 'PaidAt' => '2026-09-05', 'Method' => 'cash']);
        $this->assertSame(['partial'], array_values(array_unique(
            $this->withHeaders($this->headers($token))->getJson($url)->json('sessions.*.payment')
        )));

        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-09-06', 'Method' => 'cash']);
        $this->assertSame(['paid'], array_values(array_unique(
            $this->withHeaders($this->headers($token))->getJson($url)->json('sessions.*.payment')
        )));
    }

    public function test_other_campus_director_cannot_read_contract_sessions(): void
    {
        [, $course] = $this->countCourse(1);

        $this->withHeaders($this->headers($this->directorToken([2])))
            ->getJson("/api/v1/accounting/contracts/{$course->ID}/sessions")
            ->assertForbidden();
    }

    public function test_ledger_opens_by_student_id_and_stays_campus_scoped(): void
    {
        [$student, $course] = $this->countCourse(1);

        $this->withHeaders($this->headers($this->directorToken([1])))
            ->getJson("/api/v1/accounting/ledger?student_id={$student->id}")
            ->assertOk()
            ->assertJsonPath('courses.0.id', (int) $course->ID);
        $this->withHeaders($this->headers($this->directorToken([2])))
            ->getJson("/api/v1/accounting/ledger?student_id={$student->id}")
            ->assertForbidden();
    public function test_ledger_invoice_carries_its_service_range(): void
    {
        $token = $this->directorToken([1]);
        [$student, $course] = $this->countCourse(1);
        $invoice = $this->invoiceFor($student, $course);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => '月結費用 2026年9月', 'Amount' => 8000, 'PeriodStart' => '2026-09-28', 'PeriodEnd' => '2026-10-27']);

        $row = $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/accounting/ledger?student_class_id={$course->ID}")
            ->assertOk()
            ->json('invoices.0');

        $this->assertSame(['2026-09-28', '2026-10-27'], [$row['period_start'], $row['period_end']]);
    }

    /** @return array{0: Student, 1: StudentClass} */
    private function countCourse(int $campusId): array
    {
        $student = Student::create([
            'name' => '涵蓋上課日測試' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-01',
            'EndDate' => null,
            'TotalHours' => 8,
            'Charge' => 8000,
            'Paid' => 0,
            'Rate' => 2000,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 2,
            'UsedSessions' => 2,
            'ClassType' => 'one_on_one',
            'rate_unit' => 'session',
        ]);
        foreach ([
            ['2026-09-01', 'attended'],
            ['2026-09-08', 'attended'],
            ['2026-09-15', 'cancelled'],
            ['2026-09-22', 'scheduled'],
            ['2026-09-29', 'scheduled'],
            ['2026-10-06', 'scheduled'],
        ] as [$date, $status]) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => $status,
            ]);
        }

        return [$student, $course];
    }

    private function invoiceFor(Student $student, StudentClass $course): Invoice
    {
        return Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-08-25',
            'DueDate' => '2026-09-01',
            'TotalAmount' => 8000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
    }

    private function confirmedReport(Student $student, StudentClass $course): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-08-30',
            'payment_method' => 'cash',
            'reported_amount' => 8000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-08-30 12:00:00'),
            'confirmed_by' => 1,
            'report_token_hash' => hash('sha256', 'coverage-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    private function directorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director_cov_' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
