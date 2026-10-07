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
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/invoices', [
                'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-20', 'TotalAmount' => 6600,
                'MonthlySplit' => true, 'SplitStart' => '2026-08-20', 'SplitEnd' => '2026-08-10',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('SplitEnd');
    }

    public function test_partly_paid_slip_uses_item_range_and_unpaid_equal_total_stays_billed_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken('director-monthly-range-upcoming@example.com');
        [$student, $course] = $this->makeMonthlyCourse('月結期間預排測試', '2026-08-01', '2026-08-31');
        foreach ([['2026-08-05', 'attended'], ['2026-08-11', 'attended'], ['2026-08-15', 'scheduled'], ['2026-08-25', 'scheduled']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $slipDates = function (string $status, int $paid) use ($student, $course, $token): array {
            $invoice = Invoice::create([
                'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-17',
                'TotalAmount' => 3000, 'PaidAmount' => $paid, 'Status' => $status, 'billing_period' => '2026-08',
            ]);
            InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => '月結費用', 'Amount' => 3000, 'PeriodStart' => '2026-08-10', 'PeriodEnd' => '2026-08-20']);

            return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")->assertOk()->json('sessions.*.date');
        };

        // Partly paid: fixed amount → held + upcoming inside the 8/10–8/20 item range only.
        $this->assertSame(['2026-08-11', '2026-08-15'], $slipDates('partial', 1000));
        // Unpaid with total equal to the held lessons, and void: still billed-only.
        $this->assertSame(['2026-08-05', '2026-08-11'], $slipDates('unpaid', 0));
        $this->assertSame(['2026-08-05', '2026-08-11'], $slipDates('void', 0));

        // Paid but nothing inside the item range: stays empty, never widens to 8/25.
        ClassSession::where('StudentClassID', $course->ID)->whereIn('SessionDate', ['2026-08-11', '2026-08-15'])->delete();
        $this->assertSame([], $slipDates('paid', 3000));
    }

    public function test_unpaid_cross_month_slip_lists_held_and_upcoming_lessons(): void
    {
        // A cross-month service cycle keeps its agreed amount even while unpaid,
        // so the slip covers the cycle's upcoming lessons as well.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken('director-monthly-cross-upcoming@example.com');
        [$student, $course] = $this->makeMonthlyCourse('月結跨月未繳測試', '2026-08-30', '2026-09-28');
        foreach ([['2026-08-30', 'attended'], ['2026-09-07', 'scheduled'], ['2026-09-14', 'scheduled']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-25', 'DueDate' => '2026-08-30',
            'TotalAmount' => 6600, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-08',
        ]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => '月結費用', 'Amount' => 6600, 'PeriodStart' => '2026-08-30', 'PeriodEnd' => '2026-09-28']);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertOk()
            ->assertJsonPath('total_amount', 6600)
            ->assertJsonPath('sessions.*.date', ['2026-08-30', '2026-09-07', '2026-09-14']);
    }

    public function test_monthly_split_cross_month_and_missing_rate_invoices_keep_their_amount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken('director-monthly-split-fixed@example.com');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // MonthlySplit 8/30–9/28 (one item per month) is one cross-month cycle: no repricing
        // from August's held lessons, and the slip lists the cycle's upcoming lessons.
        [$student, $course] = $this->makeMonthlyCourse('月結拆月固定測試', '2026-08-30', '2026-09-28');
        foreach ([['2026-08-30', 'attended'], ['2026-09-07', 'scheduled']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $this->withHeaders($headers)->postJson('/api/v1/invoices', [
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-25', 'TotalAmount' => 6600,
            'billing_period' => '2026-08', 'MonthlySplit' => true, 'SplitStart' => '2026-08-30', 'SplitEnd' => '2026-09-28',
        ])->assertSuccessful();
        $splitId = Invoice::where('StudentClassID', $course->ID)->value('id');
        $this->withHeaders($headers)->getJson("/api/v1/invoices/{$splitId}/slip-data")
            ->assertOk()
            ->assertJsonPath('total_amount', 6600)
            ->assertJsonPath('sessions.*.date', ['2026-08-30', '2026-09-07']);

        // No lesson price (legacy zero rate): the stored amount is fixed, so upcoming lessons show.
        [$student2, $course2] = $this->makeMonthlyCourse('月結無單價測試', '2026-09-01', '2026-09-30');
        $course2->forceFill(['Rate' => 0, 'Charge' => 0])->save();
        foreach ([['2026-09-01', 'attended'], ['2026-09-08', 'scheduled']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $course2->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $invoice = Invoice::create([
            'StudentID' => $student2->id, 'StudentClassID' => $course2->ID, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-09',
        ]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course2->ID, 'Description' => '月結費用', 'Amount' => 5000, 'PeriodStart' => '2026-09-01', 'PeriodEnd' => '2026-09-30']);
        $this->withHeaders($headers)->getJson("/api/v1/invoices/{$invoice->id}/slip-data")
            ->assertOk()
            ->assertJsonPath('total_amount', 5000)
            ->assertJsonPath('sessions.*.date', ['2026-09-01', '2026-09-08']);
    }

    /**
     * In-app #377/#378: an ended monthly contract with no invoice is priced by its own
     * month (3 attended September lessons), not by the month the director opens it in;
     * and director-record accepts that amount instead of a month it never covered.
     */
    public function test_ended_monthly_contract_without_invoice_is_priced_by_its_own_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken('director-ended-monthly@example.com');
        [, $course] = $this->makeMonthlyCourse('月結結束測試', '2026-09-01', '2026-09-30');
        $course->update(['Rate' => 1300, 'Charge' => 5200, 'Stop' => 1, 'closed_reason' => 'settled_pending']);
        foreach (['2026-09-05' => 'leave', '2026-09-12' => 'attended', '2026-09-19' => 'attended', '2026-09-26' => 'attended', '2026-10-03' => 'attended'] as $date => $status) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->getJson("/api/v1/alerts/tuition-slip/{$course->ID}")
            ->assertOk()
            ->assertJsonPath('charge', 3900)
            ->assertJsonPath('period_sessions', 3);

        $this->withHeaders($headers)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID,
            'payment_date' => '2026-10-07',
            'payment_method' => 'cash',
            'amount' => 3900,
        ])->assertSuccessful();

        // Confirming that report bills the contract's own month, not October.
        $reportId = \App\Models\PaymentReport::where('StudentClassID', $course->ID)->value('id');
        $this->withHeaders($headers)->putJson("/api/v1/payment-reports/{$reportId}/confirm", [])->assertSuccessful();
        $this->assertSame(['2026-09'], Invoice::where('StudentClassID', $course->ID)->pluck('billing_period')->all());
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
