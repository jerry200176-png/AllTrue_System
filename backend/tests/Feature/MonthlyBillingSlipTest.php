<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyBillingSlipTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_slip_uses_five_billable_sessions_when_stored_charge_is_four(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken();
        $student = Student::create([
            'name' => '月結回歸測試',
            'CampusID' => 1,
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
            'StartDate' => '2026-07-01',
            'EndDate' => '2026-07-31',
            'TotalHours' => 10,
            'Charge' => 6000,
            'Paid' => 0,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 17,
            'monthly_sessions' => 4,
            'rate_unit' => 'session',
        ]);

        foreach (['2026-07-01', '2026-07-09', '2026-07-15', '2026-07-22', '2026-07-29'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => 'attended',
            ]);
        }
        foreach (['leave', 'cancelled', 'scheduled'] as $status) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-30',
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => $status,
            ]);
        }

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-07-17',
            'DueDate' => '2026-07-17',
            'TotalAmount' => 6000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-07',
        ]);
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '月結費用 2026年7月',
            'Amount' => 6000,
            'PeriodStart' => '2026-07-08',
            'PeriodEnd' => '2026-08-06',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition-slip/{$course->ID}");

        $response->assertOk();
        $response->assertJsonPath('charge', 7500);
        $response->assertJsonPath('period_sessions', 5);
        $response->assertJsonCount(5, 'sessions');
        $response->assertJsonPath('billing_period', '2026-07');
        $response->assertJsonPath('sessions.0.date', '2026-07-01');
        $response->assertJsonPath('sessions.0.start_time', '18:00');
        $this->assertSame($response->json('subject'), $response->json('sessions.0.subject'));

        $alerts = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $alerts->assertOk();
        $row = collect($alerts->json())->firstWhere('id', (int) $course->ID);
        $this->assertNotNull($row);
        $this->assertSame(7500, (int) $row['charge']);
        $this->assertTrue((bool) $row['invoice_amount_discrepancy']);
        $this->assertSame(6000, (int) $row['invoice_stored_amount']);
        $this->assertSame(7500, (int) $row['invoice_computed_amount']);
        $this->assertSame(5, (int) $row['invoice_period_sessions']);

        $invoiceSlip = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/{$invoice->id}/slip-data");

        $invoiceSlip->assertOk()
            ->assertJsonPath('total_amount', 7500)
            ->assertJsonPath('stored_total_amount', 6000)
            ->assertJsonPath('computed_total_amount', 7500)
            ->assertJsonPath('amount_discrepancy', true)
            ->assertJsonPath('period_sessions', 5)
            ->assertJsonPath('items.0.amount', 7500)
            ->assertJsonPath('items.0.period_start', '2026-07-01')
            ->assertJsonPath('items.0.period_end', '2026-07-31')
            ->assertJsonCount(5, 'sessions');

        $this->assertSame($response->json('sessions'), $invoiceSlip->json('sessions'));

        $item = $invoiceSlip->json('items.0');
        $this->assertStringContainsString('月結費用 2026年7月（5堂）', (string) $item['description']);

        $invoiceList = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/invoices?branch_id=1&student_id=' . $student->id);

        $invoiceList->assertOk()
            ->assertJsonPath('data.0.TotalAmount', 7500)
            ->assertJsonPath('data.0.stored_total_amount', 6000)
            ->assertJsonPath('data.0.amount_discrepancy', true);
    }

    public function test_monthly_slip_keeps_stored_charge_when_period_has_no_billable_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken('director-monthly-fallback@example.com');
        $student = Student::create([
            'name' => '月結無堂次回歸測試',
            'CampusID' => 1,
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
            'StartDate' => '2026-07-01',
            'EndDate' => '2026-07-31',
            'TotalHours' => 8,
            'Charge' => 6000,
            'Paid' => 0,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 17,
            'monthly_sessions' => 4,
            'rate_unit' => 'session',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition-slip/{$course->ID}");

        $response->assertOk();
        $response->assertJsonPath('charge', 6000);
        $response->assertJsonPath('period_sessions', 0);
        $response->assertJsonCount(0, 'sessions');
    }

    public function test_monthly_slip_lists_planned_dates_when_period_has_no_attended_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken('director-monthly-planned@example.com');
        $student = Student::create([
            'name' => '月結預排日期測試',
            'CampusID' => 1,
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
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-08-31',
            'TotalHours' => 8,
            'Charge' => 6000,
            'Paid' => 1,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 17,
            'monthly_sessions' => 4,
            'rate_unit' => 'session',
        ]);
        foreach ([['2026-08-03', 'scheduled'], ['2026-08-10', 'scheduled'], ['2026-08-17', 'cancelled'], ['2026-08-24', 'leave']] as [$date, $status]) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => $status,
            ]);
        }
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-08-01',
            'DueDate' => '2026-08-17',
            'TotalAmount' => 6000,
            'PaidAmount' => 6000,
            'Status' => 'paid',
            'billing_period' => '2026-08',
        ]);
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '月結費用 2026年8月',
            'Amount' => 6000,
            'PeriodStart' => '2026-08-01',
            'PeriodEnd' => '2026-08-31',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.date', '2026-08-03')
            ->assertJsonPath('sessions.1.date', '2026-08-10')
            ->assertJsonPath('total_amount', 6000);
    }

    public function test_prepaid_next_period_slip_lists_dates_in_item_service_range(): void
    {
        // #3445: billing_period is the month the service starts in (8/30 → 2026-08)
        // while the course starts 9/1; the slip must use the item's service range.
        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken('director-monthly-prepaid@example.com');
        $student = Student::create([
            'name' => '月結預繳下期測試',
            'CampusID' => 1,
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
            'EndDate' => '2026-09-30',
            'TotalHours' => 8,
            'Charge' => 6600,
            'Paid' => 0,
            'Rate' => 1650,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 30,
            'monthly_sessions' => 4,
            'rate_unit' => 'session',
        ]);
        foreach ([['2026-09-01', 'attended'], ['2026-09-08', 'scheduled'], ['2026-09-15', 'cancelled'], ['2026-09-22', 'rescheduled'], ['2026-09-29', 'scheduled']] as [$date, $status]) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => $status,
            ]);
        }
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-08-20',
            'DueDate' => '2026-08-30',
            'TotalAmount' => 6600,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-08',
        ]);
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '月結費用 2026年8月',
            'Amount' => 6600,
            'PeriodStart' => '2026-08-30',
            'PeriodEnd' => '2026-09-14',
        ]);
        // MonthlySplit shape: a second bounded item for the same course.
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '月結費用 2026年9月',
            'Amount' => 0,
            'PeriodStart' => '2026-09-15',
            'PeriodEnd' => '2026-09-28',
        ]);
        // A second, non-course line (material fee) must not hide the course item's range.
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'Description' => '教材費',
            'Amount' => 300,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertOk()
            ->assertJsonCount(3, 'sessions')
            ->assertJsonPath('sessions.0.date', '2026-09-01')
            ->assertJsonPath('sessions.0.status', 'attended')
            ->assertJsonPath('sessions.2.date', '2026-09-22')
            ->assertJsonPath('items.0.period_start', '2026-08-30');

        // Course Management opens the same notice by course id (tuition slip).
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/alerts/tuition-slip/{$course->ID}")
            ->assertOk()
            ->assertJsonCount(3, 'sessions')
            ->assertJsonPath('sessions.0.date', '2026-09-01');
    }

    public function test_paid_monthly_slip_lists_held_and_upcoming_lessons(): void
    {
        // #3525: a paid (fixed-amount) invoice covers the whole period, so the
        // slip lists held lessons plus the ones still to come — not a stale past 排定.
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken('director-monthly-paid-upcoming@example.com');
        [$student, $course] = $this->makeMonthlyCourse('月結已繳預排測試', '2026-08-01', '2026-08-31');
        foreach ([['2026-08-03', 'attended'], ['2026-08-05', 'scheduled'], ['2026-08-10', 'scheduled'], ['2026-08-17', 'scheduled'], ['2026-08-24', 'leave']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-17',
            'TotalAmount' => 6000, 'PaidAmount' => 6000, 'Status' => 'paid', 'billing_period' => '2026-08',
        ]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => '月結費用 2026年8月', 'Amount' => 6000, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-31']);

        $dates = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertOk()
            ->json('sessions.*.date');
        $this->assertSame(['2026-08-03', '2026-08-10', '2026-08-17'], $dates);
    }

    public function test_monthly_split_items_keep_the_requested_endpoints(): void
    {
        // #3525: SplitStart/SplitEnd off a month boundary must not widen to whole months.
        $token = $this->createDirectorToken('director-monthly-split@example.com');
        [$student, $course] = $this->makeMonthlyCourse('月結拆月測試', '2026-08-30', '2026-09-28');

        $id = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/invoices', [
                'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-20', 'TotalAmount' => 6600,
                'MonthlySplit' => true, 'SplitStart' => '2026-08-30', 'SplitEnd' => '2026-09-28',
            ])
            ->assertSuccessful()
            ->json('id') ?? Invoice::query()->latest('id')->value('id');

        $bounds = InvoiceItem::where('InvoiceID', $id)->orderBy('PeriodStart')->get()
            ->map(fn ($i) => [Carbon::parse($i->PeriodStart)->toDateString(), Carbon::parse($i->PeriodEnd)->toDateString(), (int) $i->Amount])
            ->all();
        $this->assertSame([['2026-08-30', '2026-08-31', 3300], ['2026-09-01', '2026-09-28', 3300]], $bounds);
    }

    /** @return array{0: Student, 1: StudentClass} */
    private function makeMonthlyCourse(string $name, string $start, string $end): array
    {
        $student = Student::create(['name' => $name, 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => $start, 'EndDate' => $end, 'TotalHours' => 8, 'Charge' => 6000, 'Paid' => 0, 'Rate' => 1500,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'settlement_day' => 17,
            'monthly_sessions' => 4, 'rate_unit' => 'session',
        ]);

        return [$student, $course];
    }

    public function test_count_mode_slip_uses_contract_charge_when_stored_charge_is_stale(): void
    {
        $token = $this->createDirectorToken('director-count-stale-charge@example.com');
        $student = Student::create([
            'name' => '堂數制舊金額回歸測試',
            'CampusID' => 1,
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
            'StartDate' => '2026-06-29',
            'EndDate' => '2026-09-08',
            'TotalHours' => 8,
            'Charge' => 8000,
            'Paid' => 0,
            'Rate' => 1000,
            'MDate' => now(),
            'Stop' => 1,
            'closed_reason' => 'contract_amended',
            'ScheduleMode' => 'count',
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'UsedSessions' => 4,
            'ClassType' => 'one_on_one',
            'rate_unit' => 'session',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition-slip/{$course->ID}");

        $response->assertOk()
            ->assertJsonPath('schedule_mode', 'count')
            ->assertJsonPath('remaining_sessions', 0)
            ->assertJsonPath('charge', 4000);
    }

    private function createDirectorToken(string $loginName = 'director-monthly@example.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
