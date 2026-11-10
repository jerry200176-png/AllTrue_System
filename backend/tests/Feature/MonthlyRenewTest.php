<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * b4：月結制課程續約機制。
 *
 * 修復前行為：purchaseBatch 對月結課（ScheduleMode='date'）也會新建一筆堂數制課程，
 * 破壞月結計費語意，且舊月結課不被結案，造成重複課程列。
 *
 * 修復後行為：
 * 1. renewMonthly 端點：建立新一期月結課程，舊課程結算，不再把多期帳務混在同一筆課程。
 * 2. purchaseBatch 對月結課回傳 422，防止誤用。
 * 3. 堂數制 purchaseBatch 流程完全不受影響。
 */
class MonthlyRenewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renew_monthly_rejects_trial_source(): void
    {
        // in-app #374: a monthly trial must be converted, not renewed into another trial period.
        $token = $this->createDirectorToken([1], 'director-renew-trial@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'ClassType'        => 'trial',
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'week'             => 2,
            'time'             => '18:00:00',
            'SessionDuration'  => 120,
        ]);
        $before = StudentClass::count();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => '2026-06-30'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'trial_use_convert');
        $this->assertSame($before, StudentClass::count());
    }

    public function test_renew_monthly_creates_new_period_course_and_settles_source_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'week'             => 2,
            'time'             => '18:00:00',
            'SessionDuration'  => 120,
            'Paid'             => 1,
            'Charge'           => 4800, // #2567: the prior fee must be on record before renewal
        ]);

        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate'    => '2026-05-05',
            'StartTime'      => '18:00:00',
            'EndTime'        => '20:00:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);

        $newEndDate = '2026-06-30';

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => $newEndDate,
        ]);

        $res->assertCreated()
            ->assertJsonPath('source_course.id', (int) $course->ID)
            ->assertJsonPath('source_course.closed_reason', 'settled')
            ->assertJsonPath('new_course.end_date', $newEndDate)
            ->assertJsonPath('new_course.schedule_mode', 'date')
            ->assertJsonPath('new_course.paid', 0);

        $course->refresh();
        $newCourse = StudentClass::where('StudentID', $student->id)
            ->where('ID', '<>', $course->ID)
            ->firstOrFail();

        // 舊課程不再延長；它代表已結算舊期。
        $this->assertStringStartsWith('2026-04-30', (string) $course->EndDate);
        $this->assertSame('date', (string) $course->ScheduleMode);
        $this->assertSame(15, (int) $course->settlement_day);
        $this->assertSame(8, (int) $course->monthly_sessions);
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled', (string) $course->closed_reason);

        $this->assertSame(
            2,
            StudentClass::where('StudentID', $student->id)
                ->where('SubjectID', 1)
                ->count(),
            '月結續約應建立新一期 StudentClass，避免多期帳務混在同一筆課程'
        );

        $this->assertSame(
            [
                '2026-05-05',
                '2026-05-12',
                '2026-05-19',
                '2026-05-26',
                '2026-06-02',
                '2026-06-09',
                '2026-06-16',
                '2026-06-23',
                '2026-06-30',
            ],
            $this->scheduledSessionDates($newCourse),
            '新一期月結課程應補齊自己的未來預排堂次'
        );

        $this->assertSame(
            [],
            $this->scheduledSessionDates($course),
            '舊課程結算後不可留下未來 scheduled，避免主任仍看到舊期待上'
        );

        $invoice = Invoice::where('StudentClassID', $newCourse->ID)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('2026-05', (string) $invoice->billing_period);
        $this->assertSame('unpaid', (string) $invoice->Status);
    }

    public function test_monthly_renewal_preview_and_execute_share_start_month_and_clamped_due_date(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        $token = $this->createDirectorToken([1], 'director-period-fixture@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-08-01', 'EndDate' => '2026-09-10', 'settlement_day' => 31, 'week' => 3, 'time' => '18:00:00', 'Rate' => 1500, 'monthly_sessions' => 4]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-10-31'])
            ->assertOk()->assertJsonPath('proposed_course.start_date', '2026-09-11')->assertJsonPath('billing.invoice.billing_period', '2026-09')->assertJsonPath('billing.invoice.due_date', '2026-09-30');
        $response = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => '2026-10-31'])->assertCreated();
        $invoice = Invoice::where('StudentClassID', $response->json('new_course.id'))->firstOrFail();
        $this->assertSame('2026-09', $invoice->billing_period); $this->assertSame('2026-09-30', $invoice->DueDate);
        $this->assertSame((int) $response->json('new_course.id'), (int) $invoice->items()->firstOrFail()->StudentClassID);
    }

    public function test_cycle_starting_late_april_with_may_lessons_is_labelled_may_and_next_cycle_is_not_double_billed(): void
    {
        // in-app #514: 04-29 ~ 05-27 holds 05/05, 05/12, 05/19, 05/26 -> 2026-05, not the start month.
        Carbon::setTestNow('2026-04-28 08:00:00');
        $token = $this->createDirectorToken([1], 'director-period-514@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-28', 'settlement_day' => 15, 'week' => 2, 'time' => '18:00:00', 'Rate' => 1875, 'monthly_sessions' => 4, 'Charge' => 7500, 'Paid' => 1]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-05-27'])
            ->assertOk()->assertJsonPath('proposed_course.start_date', '2026-04-29')->assertJsonPath('billing.invoice.billing_period', '2026-05');
        $first = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => '2026-05-27'])->assertCreated();
        $invoice = Invoice::where('StudentClassID', $first->json('new_course.id'))->firstOrFail();
        $this->assertSame('2026-05', $invoice->billing_period);
        $this->assertSame(7500, (int) $invoice->TotalAmount, 'the label moves, the amount does not');
        $this->assertSame('月結費用 2026年5月', $invoice->items()->firstOrFail()->Description, 'the slip line names the billed month, not the start month');

        Carbon::setTestNow('2026-05-27 08:00:00');
        $headers['Authorization'] = 'Bearer ' . $this->createDirectorToken([1], 'director-period-514b@example.com');
        $second = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$first->json('new_course.id')}/renew-monthly", ['end_date' => '2026-06-24'])->assertCreated();
        $this->assertSame('2026-06', Invoice::where('StudentClassID', $second->json('new_course.id'))->firstOrFail()->billing_period);
        $this->assertSame(1, Invoice::whereIn('StudentClassID', [$first->json('new_course.id'), $second->json('new_course.id')])->where('billing_period', '2026-05')->count());
    }

    public function test_monthly_renewal_blocks_completed_sessions_outside_source_contract_without_writes(): void
    {
        Carbon::setTestNow('2026-09-29 08:00:00');
        $token = $this->createDirectorToken([1], 'director-outside-fixture@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-07-27', 'EndDate' => '2026-09-10', 'settlement_day' => 31]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-23', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-09-30'])
            ->assertStatus(422)->assertJsonPath('severity', 'blocked')->assertJsonPath('blockers.0.code', 'monthly_completed_sessions_outside_contract');
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => '2026-09-30'])
            ->assertStatus(422)->assertJsonPath('code', 'monthly_completed_sessions_outside_contract');
        $this->assertSame(1, StudentClass::count()); $this->assertSame(0, Invoice::count());
        $this->assertSame('attended', ClassSession::firstOrFail()->Status); $this->assertSame(0, (int) $course->fresh()->Stop);
    }

    public function test_lesson_after_a_lesson_month_labelled_cycle_is_not_hidden_by_its_label(): void
    {
        // in-app #514 review: cycle 04-29 ~ 05-27 is labelled 2026-05, yet a 05-28 lesson is outside its range and still needs handling.
        Carbon::setTestNow('2026-05-29 08:00:00');
        $token = $this->createDirectorToken([1], 'director-outside-514@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-04-29', 'EndDate' => '2026-05-27', 'settlement_day' => 15]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-29', 'DueDate' => '2026-05-15', 'billing_period' => '2026-05', 'TotalAmount' => 7500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'cycle', 'Amount' => 7500, 'PeriodStart' => '2026-04-29', 'PeriodEnd' => '2026-05-27']);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-28', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-06-26'])
            ->assertStatus(422)->assertJsonPath('blockers.0.code', 'monthly_completed_sessions_outside_contract');
    }

    public function test_another_subjects_item_range_on_a_shared_bill_does_not_hide_a_lesson(): void
    {
        // in-app #514 review: a bill anchored on subject 1 with a subject-2 item covering 05-28 ~ 06-30 must not cover subject 1's 05-28 lesson.
        Carbon::setTestNow('2026-05-29 08:00:00');
        $token = $this->createDirectorToken([1], 'director-shared-514@example.com');
        $student = $this->createStudent();
        $one = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'SubjectID' => 1, 'StartDate' => '2026-04-29', 'EndDate' => '2026-05-27', 'settlement_day' => 15]);
        $two = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'SubjectID' => 2, 'StartDate' => '2026-05-28', 'EndDate' => '2026-06-30', 'settlement_day' => 15]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $one->ID, 'IssueDate' => '2026-04-29', 'DueDate' => '2026-05-15', 'billing_period' => '2026-05', 'TotalAmount' => 9000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $one->ID, 'Description' => 'one', 'Amount' => 7500, 'PeriodStart' => '2026-04-29', 'PeriodEnd' => '2026-05-27']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $two->ID, 'Description' => 'two', 'Amount' => 1500, 'PeriodStart' => '2026-05-28', 'PeriodEnd' => '2026-06-30']);
        ClassSession::create(['StudentClassID' => $one->ID, 'SessionDate' => '2026-05-28', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$one->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-06-26'])
            ->assertStatus(422)->assertJsonPath('blockers.0.code', 'monthly_completed_sessions_outside_contract');
        $resolved = app(\App\Services\BillingPayableResolver::class)->byStudentClassIds([(int) $one->ID], [$one])[(int) $one->ID];
        $this->assertSame(['2026-04-29', '2026-05-27'], [$resolved['payable_period_start'], $resolved['payable_period_end']], 'cross-month cycle range reaches the payment form');
    }

    public function test_a_lesson_in_the_gap_between_two_item_ranges_is_not_covered(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $token = $this->createDirectorToken([1], 'director-gap-514@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31', 'settlement_day' => 15]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-15', 'billing_period' => '2026-08', 'TotalAmount' => 6000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        foreach ([['2026-08-01', '2026-08-31'], ['2026-10-01', '2026-10-31']] as [$a, $b]) {
            InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'x', 'Amount' => 3000, 'PeriodStart' => $a, 'PeriodEnd' => $b]);
        }
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-15', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertStatus(422)->assertJsonPath('blockers.0.code', 'monthly_completed_sessions_outside_contract');
    }

    public function test_cycle_label_skips_a_month_another_contract_already_bills(): void
    {
        // in-app #514 review: 04-29 ~ 05-27 only has May lessons and May is already billed by another contract of the same student+subject:
        // the label falls back to the cycle start month (April) instead of sharing May.
        Carbon::setTestNow('2026-04-28 08:00:00');
        $token = $this->createDirectorToken([1], 'director-taken-514@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'SubjectID' => 1, 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-28', 'settlement_day' => 15, 'week' => 2, 'time' => '18:00:00', 'Rate' => 1875, 'monthly_sessions' => 4, 'Charge' => 7500, 'Paid' => 1]);
        $other = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'SubjectID' => 1, 'StartDate' => '2026-05-28', 'EndDate' => '2026-05-31', 'Stop' => 1]);
        Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $other->ID, 'IssueDate' => '2026-04-20', 'DueDate' => '2026-05-15', 'billing_period' => '2026-05', 'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-05-27'])
            ->assertOk()->assertJsonPath('billing.invoice.billing_period', '2026-04');
    }

    public function test_unconfirmed_monthly_forecast_cannot_be_registered_as_received_cash(): void
    {
        Carbon::setTestNow('2026-09-29 08:00:00');
        $token = $this->createDirectorToken([1], 'director-forecast-fixture@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-09-11', 'EndDate' => '2026-09-30', 'Rate' => 1500, 'Charge' => 4500, 'Paid' => 0, 'settlement_day' => 31]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-30', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'scheduled']);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-29', 'DueDate' => '2026-09-30', 'billing_period' => '2026-09', 'TotalAmount' => 4500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID, 'invoice_id' => $invoice->id, 'amount' => 4500, 'payment_method' => 'cash', 'payment_date' => '2026-09-29', 'reported_by_name' => 'Fixture payer',
        ])->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')->assertJsonPath('expected_amount', 0);
        $this->assertSame(0, \App\Models\PaymentReport::count()); $this->assertSame(0, \App\Models\Payment::count());
        $this->assertSame(0, (int) $course->fresh()->Paid); $this->assertSame(4500, (int) $invoice->fresh()->TotalAmount);
        $report = \App\Models\PaymentReport::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'Legacy forecast', 'reported_amount' => 4500, 'payment_method' => 'cash', 'payment_date' => '2026-09-29',
            'status' => 'pending', 'report_token_hash' => hash('sha256', 'forecast-pending-fixture'), 'token_expires_at' => now()]);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->putJson("/api/v1/payment-reports/{$report->id}/confirm")
            ->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')->assertJsonPath('expected_amount', 0);
        $this->assertSame('pending', $report->fresh()->status); $this->assertSame(0, \App\Models\Payment::count());
        $this->assertSame(4500, (int) $invoice->fresh()->TotalAmount); $this->assertSame(0, (int) $course->fresh()->Paid);
    }

    public function test_updating_monthly_course_schedule_creates_fixed_future_sessions_for_detail_view(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-edit@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-05-31',
            'week'             => 2,
            'time'             => '18:00:00',
            'SessionDuration'  => 120,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'duration_hours'   => 2,
            'payment_type'     => 'monthly',
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'days_of_week'     => [4],
            'start_time'       => '17:00',
            'day_time_slots'   => [['day' => 4, 'start_time' => '17:00']],
            'first_class_date' => '2026-04-01',
        ]);

        $res->assertOk();

        $this->assertSame(
            ['2026-04-30', '2026-05-07', '2026-05-14', '2026-05-21', '2026-05-28'],
            $this->scheduledSessionDates($course),
            '編輯月結固定時段後，課程管理詳情應顯示該月週期性預排堂次'
        );

        $this->assertSame(
            ['17:00:00'],
            ClassSession::where('StudentClassID', $course->ID)
                ->distinct()
                ->orderBy('StartTime')
                ->pluck('StartTime')
                ->all()
        );
    }

    public function test_legacy_monthly_course_without_end_date_still_returns_current_month_fixed_dates_for_detail_view(): void
    {
        $token = $this->createDirectorToken([1], 'director-legacy-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 8,
            'RemainingSessions' => 0,
            'settlement_day'   => 31,
            'monthly_sessions' => null,
            'StartDate'        => '2026-04-01',
            'EndDate'          => null,
            'week'             => 1,
            'time'             => '17:00:00',
            'week1'            => 4,
            'time1'            => '17:00:00',
            'duration1'        => 120,
            'SessionDuration'  => 120,
            'Paid'             => 0,
        ]);

        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate'    => '2026-04-06',
            'StartTime'      => '17:00:00',
            'EndTime'        => '19:00:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates?branch_id=1', [
            'branch_id'   => 1,
            'range_start' => '2026-04-01',
            'range_end'   => '2026-04-30',
            'courses'     => [[
                'id'               => (int) $course->ID,
                'first_class_date' => '2026-04-01',
                'sessions_purchased' => 0,
                'days_of_week'     => [1, 4],
            ]],
        ]);

        $res->assertOk();

        $split = $res->json((string) $course->ID);
        $this->assertIsArray($split);
        $this->assertArrayHasKey('materialized', $split);
        $this->assertArrayHasKey('projected', $split);

        $dates = array_values(array_unique(array_merge(
            array_column($split['materialized'], 'session_date'),
            array_column($split['projected'], 'session_date')
        )));
        sort($dates);

        $this->assertSame(
            [
                '2026-04-02',
                '2026-04-06',
                '2026-04-09',
                '2026-04-13',
                '2026-04-16',
                '2026-04-20',
                '2026-04-23',
                '2026-04-27',
                '2026-04-30',
            ],
            $dates,
            'legacy 月結課即使 EndDate 缺失且只有部分 ClassSession，詳情仍應推算本月固定時段日期'
        );
    }

    public function test_director_can_materialize_projected_monthly_session_once_for_editing(): void
    {
        $token = $this->createDirectorToken([1], 'director-materialize-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 8,
            'RemainingSessions' => 0,
            'StartDate'        => '2026-04-01',
            'EndDate'          => null,
            'week'             => 1,
            'time'             => '17:00:00',
            'week1'            => 4,
            'time1'            => '17:00:00',
            'duration1'        => 120,
            'SessionDuration'  => 120,
        ]);

        $payload = [
            'student_class_id' => (int) $course->ID,
            'session_date'     => '2026-04-02',
            'start_time'       => '17:00',
            'branch_id'        => 1,
        ];

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', $payload);

        $first->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('session.student_class_id', (int) $course->ID)
            ->assertJsonPath('session.session_date', '2026-04-02')
            ->assertJsonPath('session.start_time', '17:00')
            ->assertJsonPath('session.end_time', '19:00')
            ->assertJsonPath('session.status', 'scheduled');

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', $payload);

        $second->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('session.id', $first->json('session.id'));

        $this->assertSame(
            1,
            ClassSession::where('StudentClassID', $course->ID)
                ->whereDate('SessionDate', '2026-04-02')
                ->where('StartTime', '17:00:00')
                ->count(),
            'materialize projected session must be idempotent'
        );
    }

    public function test_scheduled_exception_projection_can_be_materialized_and_cancelled(): void
    {
        $token = $this->createDirectorToken([1], 'director-scheduled-exception@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-04-30',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'week' => 7,
            'time' => '10:00:00',
            'SessionDuration' => 120,
        ]);

        $schedule = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);

        $unverifiableSchedule = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '?拍?',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
            'original_schedule_id' => 999999,
        ]);

        $extraSchedule = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '?拍?',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'extra',
            'student_course_id' => $course->ID,
        ]);

        $sameDayAnchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'rescheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        $sameDayDestination = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
            'original_schedule_id' => $sameDayAnchor->id,
        ]);

        $crossDateAnchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '?拍?',
            'day_of_week' => 2,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-07',
            'status' => 'rescheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        $crossDateDestination = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '?拍?',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
            'original_schedule_id' => $crossDateAnchor->id,
        ]);

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v1/class-sessions/ensure-projected', [
                'student_class_id' => (int) $course->ID,
                'session_date' => '2026-04-08',
                'start_time' => '10:00',
                'branch_id' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('session.session_date', '2026-04-08')
            ->assertJsonPath('session.start_time', '10:00')
            ->assertJsonPath('session.end_time', '12:00');

        $session = ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-04-08')
            ->where('StartTime', '10:00:00')
            ->firstOrFail();

        $this->withHeaders($headers)
            ->patchJson("/api/v1/class-sessions/{$session->id}", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('schedules', [
            'id' => $unverifiableSchedule->id,
            'status' => 'cancelled',
        ]);
        $this->assertSame('scheduled', (string) $extraSchedule->fresh()->status);
        // in-app #516: a cross-date reschedule destination is owned by the occurrence materialized from it,
        // so cancelling that session cancels its schedule row (otherwise /schedules keeps listing it).
        $this->assertSame('cancelled', (string) $crossDateDestination->fresh()->status);
        $this->assertSame('cancelled', (string) $sameDayDestination->fresh()->status);
        $this->assertDatabaseHas('ClassSession', [
            'id' => $session->id,
            'IsContractException' => 1,
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'id' => $session->id,
            'Status' => 'cancelled',
        ]);

        // The course may still have independent extra/reschedule rows on this
        // date; session-dates intentionally keeps those occurrences visible.
        // The cancellation assertion must stay scoped to the linked exception
        // row rather than treating every date occurrence as that ClassSession.
    }

    public function test_cancelling_a_regular_session_does_not_cancel_same_day_substitute_schedule(): void
    {
        $token = $this->createDirectorToken([1], 'director-regular-session-cancel@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-04-30',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'week' => 3,
            'time' => '10:00:00',
            'SessionDuration' => 120,
        ]);

        $anchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'rescheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        $substitute = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => 1234,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
            'original_schedule_id' => $anchor->id,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-08',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'scheduled',
            'Note' => 'regular-contract-session',
            'IsContractException' => 0,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$session->id}", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('scheduled', (string) $substitute->fresh()->status);
    }

    public function test_scheduled_exception_projection_uses_stored_time_when_contract_time_is_requested(): void
    {
        $token = $this->createDirectorToken([1], 'director-scheduled-exception-time@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-04-30',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'week' => 7,
            'time' => '10:00:00',
            'SessionDuration' => 120,
        ]);

        $anchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'rescheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);

        Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '14:30',
            'end_time' => '16:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
            'original_schedule_id' => $anchor->id,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', [
            'student_class_id' => (int) $course->ID,
            'session_date' => '2026-04-08',
            // The projected chip carries the recurring contract time, not the
            // persisted exception time.
            'start_time' => '10:00',
            'branch_id' => 1,
        ])->assertOk()
            ->assertJsonPath('session.session_date', '2026-04-08')
            ->assertJsonPath('session.start_time', '14:30')
            ->assertJsonPath('session.end_time', '16:00');
    }

    public function test_scheduled_exception_projection_ignores_makeups_and_unverifiable_anchors(): void
    {
        $token = $this->createDirectorToken([1], 'director-scheduled-exception-guards@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-04-30',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'week' => 7,
            'time' => '10:00:00',
            'SessionDuration' => 120,
        ]);

        $extra = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '物理',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'extra',
            'student_course_id' => $course->ID,
        ]);

        $unverifiable = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $course->TeacherID,
            'subject' => '?拍?',
            'day_of_week' => 3,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
            'status' => 'scheduled',
            'type' => 'normal',
            'student_course_id' => $course->ID,
            'original_schedule_id' => 999998,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', [
            'student_class_id' => (int) $course->ID,
            'session_date' => '2026-04-08',
            'start_time' => '10:00',
            'branch_id' => 1,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('ClassSession', [
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-08',
        ]);
        $this->assertSame('scheduled', (string) $extra->fresh()->status);
        $this->assertSame('scheduled', (string) $unverifiable->fresh()->status);
    }

    public function test_cross_date_reschedule_destination_can_be_materialized_from_phantom_chip(): void
    {
        // in-app #516: Fri 20:00 moved to Sat 17:00; Saturday is not a contract day, so the chip carried
        // the fallback contract time (20:00) and ensure-projected answered 422.
        $token = $this->createDirectorToken([1], 'director-cross-date-destination@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-04-30',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'week' => 5,
            'time' => '20:00:00',
            'SessionDuration' => 120,
        ]);
        $anchor = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 5, 'start_time' => '20:00', 'end_time' => '22:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-10', 'status' => 'rescheduled', 'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 6, 'start_time' => '17:00', 'end_time' => '19:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-11', 'status' => 'scheduled', 'type' => 'normal',
            'student_course_id' => $course->ID, 'original_schedule_id' => $anchor->id,
        ]);

        ClassSession::create([
            'StudentClassID' => $course->ID, 'SubjectID' => $course->SubjectID, 'SessionDate' => '2026-04-10',
            'StartTime' => '20:00:00', 'EndTime' => '22:00:00', 'Status' => 'cancelled',
        ]);

        // The same shared projection must not label the chip with the contract fallback time (20:00).
        $projected = app(\App\Services\SessionProjectionReadService::class)
            ->buildProjectedFromEffectiveDates((int) $course->ID, ['2026-04-11'], [], $course);
        $this->assertSame('17:00', $projected[0]['start_time']);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/ensure-projected', [
                'student_class_id' => (int) $course->ID,
                'session_date' => '2026-04-11',
                'start_time' => '20:00',
                'branch_id' => 1,
            ])->assertOk()
            ->assertJsonPath('session.start_time', '17:00')
            ->assertJsonPath('session.end_time', '19:00')
            ->assertJsonPath('created', true);

        $sessionId = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-11')->value('id');
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->patchJson("/api/v1/class-sessions/{$sessionId}", ['status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', (string) Schedule::where('original_schedule_id', $anchor->id)->value('status'));
    }

    public function test_cross_date_destination_is_refused_while_source_session_is_still_active(): void
    {
        $token = $this->createDirectorToken([1], 'director-cross-date-partial@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date', 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30',
            'SessionCount' => 0, 'RemainingSessions' => 0, 'week' => 5, 'time' => '20:00:00', 'SessionDuration' => 120,
        ]);
        $anchor = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 5, 'start_time' => '20:00', 'end_time' => '22:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-10', 'status' => 'rescheduled', 'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 6, 'start_time' => '17:00', 'end_time' => '19:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-11', 'status' => 'scheduled', 'type' => 'normal',
            'student_course_id' => $course->ID, 'original_schedule_id' => $anchor->id,
        ]);
        ClassSession::create([
            'StudentClassID' => $course->ID, 'SubjectID' => $course->SubjectID, 'SessionDate' => '2026-04-10',
            'StartTime' => '20:00:00', 'EndTime' => '22:00:00', 'Status' => 'scheduled',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/ensure-projected', [
                'student_class_id' => (int) $course->ID, 'session_date' => '2026-04-11',
                'start_time' => '17:00', 'branch_id' => 1,
            ])->assertStatus(422);
    }

    public function test_moved_in_lesson_does_not_override_regular_slot_on_same_contract_weekday(): void
    {
        $token = $this->createDirectorToken([1], 'director-moved-in-regular@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date', 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30',
            'SessionCount' => 0, 'RemainingSessions' => 0, 'week' => 5, 'time' => '20:00:00', 'SessionDuration' => 120,
        ]);
        $anchor = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 4, 'start_time' => '18:00', 'end_time' => '20:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-09', 'status' => 'rescheduled', 'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 5, 'start_time' => '17:00', 'end_time' => '19:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-17', 'status' => 'scheduled', 'type' => 'normal',
            'student_course_id' => $course->ID, 'original_schedule_id' => $anchor->id,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/ensure-projected', [
                'student_class_id' => (int) $course->ID, 'session_date' => '2026-04-17',
                'start_time' => '20:00', 'branch_id' => 1,
            ])->assertOk()
            ->assertJsonPath('session.start_time', '20:00')
            ->assertJsonPath('session.end_time', '22:00');
    }

    public function test_same_day_reschedule_on_contract_weekday_still_resolves_moved_time(): void
    {
        $token = $this->createDirectorToken([1], 'director-same-day-moved@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date', 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30',
            'SessionCount' => 0, 'RemainingSessions' => 0, 'week' => 3, 'time' => '10:00:00', 'SessionDuration' => 120,
        ]);
        $anchor = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 3, 'start_time' => '10:00', 'end_time' => '12:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-08', 'status' => 'rescheduled', 'type' => 'normal',
            'student_course_id' => $course->ID,
        ]);
        Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 3, 'start_time' => '14:30', 'end_time' => '16:00', 'branch_id' => 1,
            'schedule_date' => '2026-04-08', 'status' => 'scheduled', 'type' => 'normal',
            'student_course_id' => $course->ID, 'original_schedule_id' => $anchor->id,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/ensure-projected', [
                'student_class_id' => (int) $course->ID, 'session_date' => '2026-04-08',
                'start_time' => '10:00', 'branch_id' => 1,
            ])->assertOk()
            ->assertJsonPath('session.start_time', '14:30');
    }

    public function test_same_day_reschedule_wins_over_moved_in_lesson_on_contract_weekday(): void
    {
        $token = $this->createDirectorToken([1], 'director-same-day-and-moved-in@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date', 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30',
            'SessionCount' => 0, 'RemainingSessions' => 0, 'week' => 5, 'time' => '20:00:00', 'SessionDuration' => 120,
        ]);
        $mk = fn (string $date, string $start, string $end, string $status, ?int $orig = null) => Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $course->TeacherID, 'subject' => '物理',
            'day_of_week' => 5, 'start_time' => $start, 'end_time' => $end, 'branch_id' => 1,
            'schedule_date' => $date, 'status' => $status, 'type' => 'normal',
            'student_course_id' => $course->ID, 'original_schedule_id' => $orig,
        ]);
        $a1 = $mk('2026-04-10', '20:00', '22:00', 'rescheduled');
        $mk('2026-04-10', '17:00', '19:00', 'scheduled', $a1->id);
        $a2 = $mk('2026-04-03', '20:00', '22:00', 'rescheduled');
        ClassSession::create([
            'StudentClassID' => $course->ID, 'SubjectID' => $course->SubjectID, 'SessionDate' => '2026-04-03',
            'StartTime' => '20:00:00', 'EndTime' => '22:00:00', 'Status' => 'cancelled',
        ]);
        $mk('2026-04-10', '15:00', '16:00', 'scheduled', $a2->id);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/ensure-projected', [
                'student_class_id' => (int) $course->ID, 'session_date' => '2026-04-10',
                'start_time' => '20:00', 'branch_id' => 1,
            ])->assertOk()
            ->assertJsonPath('session.start_time', '17:00');
    }

    public function test_materializing_projected_session_rejects_inactive_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-materialize-inactive@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate'    => '2026-04-01',
            'week'         => 4,
            'time'         => '17:00:00',
            'Stop'         => 1,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/ensure-projected', [
            'student_class_id' => (int) $course->ID,
            'session_date'     => '2026-04-02',
            'start_time'       => '17:00',
            'branch_id'        => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', '課程已結案或停用，不能新增堂次');
    }

    public function test_renew_monthly_rejects_session_mode_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-reject@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 2,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2099-12-31',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_renew_monthly_rejects_past_date(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-past@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'settlement_day'   => 10,
            'monthly_sessions' => 4,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2020-01-01',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_purchase_batch_rejects_monthly_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-pb-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 20,
            'monthly_sessions' => 6,
            'EndDate'          => '2026-05-31',
            'Rate'             => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions'   => 8,
            'start_date' => '2026-06-01',
            'mode'       => 'new_purchase',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);

        // 舊課程未變更
        $course->refresh();
        $this->assertSame('date', (string) $course->ScheduleMode);
        $this->assertSame(0, (int) $course->Stop);

        // 沒有新 StudentClass 被建立
        $this->assertSame(
            1,
            StudentClass::where('StudentID', $student->id)->count(),
            'purchaseBatch 對月結課應被拒絕，不應新建任何課程'
        );
    }

    // ── FR-002 / FR-003：renew-monthly 建立新一期課程與逐期帳單 ──────────────

    public function test_renew_monthly_creates_invoice_on_new_period_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-invoice-create@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'Paid'             => 1,
            'Charge'           => 4800,
            'Rate'             => 600,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ]);

        $res->assertCreated();

        $course->refresh();
        $newCourseId = (int) $res->json('new_course.id');
        $newCourse = StudentClass::findOrFail($newCourseId);

        $this->assertSame(1, (int) $course->Paid, '舊期已繳狀態應保留供歷史對帳');
        $this->assertSame(1, (int) $course->Stop, '舊期應結算成歷史課程');
        $this->assertSame(0, (int) $newCourse->Paid, '新一期課程應顯示未繳');
        $this->assertNull($newCourse->PayDate, '新一期 PayDate 應為空');

        // FR-002：建立新期 Invoice
        $this->assertDatabaseHas('Invoice', [
            'StudentClassID' => $newCourseId,
            'billing_period' => '2026-05',
            'Status'         => 'unpaid',
        ]);

        $invoice = Invoice::where('StudentClassID', $newCourseId)
            ->where('billing_period', '2026-05')
            ->first();
        $this->assertNotNull($invoice);
        $this->assertSame(4800, (int) $invoice->TotalAmount, 'TotalAmount 應從 Charge 取值');

        // FR-009：同步建立 InvoiceItem
        $this->assertDatabaseHas('InvoiceItem', [
            'InvoiceID' => $invoice->id,
        ]);
    }

    public function test_renew_monthly_proceeds_with_prior_unresolved_invoice_and_keeps_periods_separate(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-outstanding@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'Paid'             => 0,
            'Charge'           => 4800,
            'Rate'             => 600,
        ]);
        $priorInvoice = Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => '2026-04-01',
            'DueDate'        => '2026-04-15',
            'TotalAmount'    => 4800,
            'PaidAmount'     => 0,
            'ScheduleModeAtIssue' => 'date',
            'Status'         => 'unpaid',
            'Note'           => '',
            'billing_period' => '2026-04',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ]);

        $res->assertCreated();
        $newCourseId = (int) $res->json('new_course.id');

        $this->assertDatabaseHas('Invoice', [
            'id'             => $priorInvoice->id,
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'billing_period' => '2026-04',
            'Status'         => 'unpaid',
            'PaidAmount'     => 0,
        ]);
        $this->assertDatabaseHas('Invoice', [
            'StudentID'      => $student->id,
            'StudentClassID' => $newCourseId,
            'billing_period' => '2026-05',
            'Status'         => 'unpaid',
        ]);
        $this->assertSame(2, Invoice::where('StudentID', $student->id)->count());
        // The unpaid old period must stay in the accounting queue (木柵 2026-10-05: unpaid months vanished as 'settled').
        $this->assertSame('settled_pending', (string) $course->fresh()->closed_reason);
        $this->getJson('/api/v1/accounting/settled-courses?branch_id=1', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonFragment(['student_class_id' => $course->ID, 'pending_reconciliation' => true]);
    }

    public function test_renew_monthly_stale_paid_flag_with_open_invoice_stays_pending(): void
    {
        $token = $this->createDirectorToken([1], 'director-renew-stale-paid@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date', 'SessionCount' => 0, 'RemainingSessions' => 0, 'settlement_day' => 15,
            'monthly_sessions' => 8, 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30',
            'Paid' => 1, 'Charge' => 4800, 'Rate' => 600, // stale flag: the April bill below is still open
        ]);
        Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01', 'DueDate' => '2026-04-15',
            'TotalAmount' => 4800, 'PaidAmount' => 0, 'ScheduleModeAtIssue' => 'date', 'Status' => 'unpaid', 'Note' => '',
            'billing_period' => '2026-04',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertCreated();

        $this->assertSame('settled_pending', (string) $course->fresh()->closed_reason);
    }

    public function test_renew_monthly_invoice_creation_is_idempotent(): void
    {
        $token = $this->createDirectorToken([1], 'director-invoice-idempotent@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'   => 'date',
            'settlement_day' => 15,
            'EndDate'        => '2026-04-30',
            'Paid'           => 1,
            'Charge'         => 4800,
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$course->ID}/renew-monthly",
            ['end_date' => '2026-05-31']
        )->assertCreated();

        $this->withHeaders($headers)->postJson(
            "/api/v1/student-classes/{$course->ID}/renew-monthly",
            ['end_date' => '2026-05-31']
        )->assertStatus(409);

        // 不應建立兩筆相同期間的新課程 / Invoice
        $this->assertSame(
            1,
            Invoice::where('billing_period', '2026-05')
                ->whereIn('StudentClassID', StudentClass::where('StudentID', $student->id)->pluck('ID'))
                ->count(),
            '重複 renew-monthly 不應重複建立 Invoice'
        );
        $this->assertSame(
            2,
            StudentClass::where('StudentID', $student->id)->count(),
            '重複 renew-monthly 不應重複建立 StudentClass'
        );
    }

    public function test_renew_monthly_recalculates_session_count_and_charge_from_new_period_slots(): void
    {
        $token = $this->createDirectorToken([1], 'director-monthly-recalc@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'settlement_day'   => 31,
            'monthly_sessions' => 8,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'week'             => 1, // Monday
            'time'             => '18:00:00',
            'SessionDuration'  => 120,
            'Paid'             => 1,
            'Charge'           => 13200, // legacy value from 8 sessions
            'Rate'             => 1650,
            'rate_unit'        => 'session',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ]);

        $res->assertCreated()
            ->assertJsonPath('invoice.total_amount', 6600);

        $newCourseId = (int) $res->json('new_course.id');
        $newCourse = StudentClass::findOrFail($newCourseId);
        $this->assertSame(4, (int) $newCourse->SessionCount);
        $this->assertSame(4, (int) $newCourse->RemainingSessions);
        $this->assertSame(6600, (int) $newCourse->Charge);
    }

    /**
     * GitHub #1096 (in-app #190) regression: Sunday-slot (week=7, ISO) monthly courses
     * generated ZERO period sessions because the weekly builder compared Carbon
     * dayOfWeek (0=Sunday) against the ISO weekday. renew-monthly then computed
     * SessionCount=0 / Charge=0 and issued an NT$0 invoice (洪子勛 6/1 case).
     */
    public function test_renew_monthly_sunday_course_computes_sessions_and_charge(): void
    {
        $token = $this->createDirectorToken([1], 'director-monthly-sunday@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'date',
            'SessionCount'      => 0,
            'RemainingSessions' => 0,
            'settlement_day'    => 31,
            'monthly_sessions'  => null,
            'StartDate'         => '2026-04-01',
            'EndDate'           => '2026-04-30',
            'week'              => 7, // ISO Sunday — the convention stored in DB
            'time'              => '10:00:00',
            'SessionDuration'   => 120,
            'Paid'              => 1,
            'Charge'            => 0, // prior period already zero-charged (production shape)
            'Rate'              => 1000,
            'rate_unit'         => 'session',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ]);

        // May 2026 Sundays: 5/3, 5/10, 5/17, 5/24, 5/31 → 5 sessions × NT$1000
        $res->assertCreated()
            ->assertJsonPath('invoice.total_amount', 5000);

        $newCourse = StudentClass::findOrFail((int) $res->json('new_course.id'));
        $this->assertSame(5, (int) $newCourse->SessionCount, 'Sunday slots must be counted in the new period');
        $this->assertSame(5000, (int) $newCourse->Charge, 'Charge must not collapse to 0 for Sunday courses');

        $this->assertSame(
            ['2026-05-03', '2026-05-10', '2026-05-17', '2026-05-24', '2026-05-31'],
            $this->scheduledSessionDates($newCourse),
            'New period must materialize its Sunday sessions'
        );
    }

    public function test_legacy_monthly_course_without_any_fee_is_refused_until_the_prior_fee_is_entered(): void
    {
        // #2567 (Founder 2026-10-13): this used to create a NT$0 invoice and settle the old term
        // ("主任手動核帳時再填金額"); a prior term with no fee on record now blocks the renewal.
        $token = $this->createDirectorToken([1], 'director-invoice-legacy@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'   => 'date',
            'settlement_day' => 15,
            'EndDate'        => '2026-04-30',
            'Paid'           => 0,
            'Charge'         => 0,
            'Rate'           => 0,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-05-31',
        ])->assertStatus(422)->assertJsonPath('code', 'prior_fee_missing');

        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, StudentClass::where('StudentID', $student->id)->count());
    }

    // ── FR-006：directorRecord 優先找當月 billing_period Invoice ─────────────

    public function test_director_record_uses_current_billing_period_invoice(): void
    {
        $token = $this->createDirectorToken([1], 'director-record-period@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'   => 'date',
            'settlement_day' => 15,
            'Paid'           => 0,
            'Charge'         => 4800,
        ]);

        // 預先建立當月未繳帳單（模擬 renew-monthly 已建立）
        $currentPeriod = now()->format('Y-m');
        $invoice = Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => now()->toDateString(),
            'TotalAmount'    => 4800,
            'PaidAmount'     => 0,
            'Status'         => 'unpaid',
            'Note'           => '',
            'billing_period' => $currentPeriod,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => (int) $course->ID,
            'payment_date'     => now()->toDateString(),
            'payment_method'   => 'cash',
            'amount'           => 4800,
        ]);

        $res->assertOk();

        $invoice->refresh();
        $this->assertSame('unpaid', $invoice->Status);
        $this->assertSame(0, (int) $invoice->PaidAmount);
        $course->refresh();
        $this->assertSame(0, (int) $course->Paid);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson('/api/v1/payment-reports/' . $res->json('report_id') . '/confirm')
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->Status, '當月 Invoice 應被標為 paid');
        $this->assertSame(4800, (int) $invoice->PaidAmount);

        $course->refresh();
        $this->assertSame(1, (int) $course->Paid, '核帳後 StudentClass.Paid 應為 1');
    }

    public function test_director_record_rejects_payment_for_tutoring_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-record-zero@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'count',
            'ClassType'    => 'tutoring',
            'Paid'         => 0,
            'Charge'       => 0,
            'Rate'         => 0,
        ]);

        $invoice = Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => now()->toDateString(),
            'TotalAmount'    => 0,
            'PaidAmount'     => 0,
            'Status'         => 'unpaid',
            'Note'           => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => (int) $course->ID,
            'payment_date'     => now()->toDateString(),
            'payment_method'   => 'cash',
            'amount'           => 0,
            'note'             => '免費課程結算',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'tutoring_no_payment_obligation');

        $invoice->refresh();
        $this->assertSame('unpaid', $invoice->Status);
        $course->refresh();
        $this->assertSame(0, (int) $course->Paid);
        $this->assertDatabaseMissing('payment_reports', [
            'StudentClassID' => $course->ID,
        ]);
    }

    public function test_purchase_batch_still_works_for_session_mode(): void
    {
        $token = $this->createDirectorToken([1], 'director-pb-session@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'UsedSessions'      => 7,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'Charge'            => 4000,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions'   => 6,
            'start_date' => '2026-04-07',
            'mode'       => 'new_purchase',
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'new_purchase')
            ->assertJsonPath('new_course.session_count', 6);

        // 確認真的有新課程建立（堂數制不受影響）
        $this->assertSame(
            2,
            StudentClass::where('StudentID', $student->id)->count(),
            '堂數制 purchase-batch 應正常新建課程'
        );
    }

    public function test_purchase_batch_rejects_duplicate_active_session_batch(): void
    {
        $token = $this->createDirectorToken([1], 'director-pb-duplicate@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);
        $duplicate = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 6,
            'RemainingSessions' => 6,
            'Paid'              => 0,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'StartDate'         => '2026-05-05',
            'EndDate'           => '2026-06-09',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions'   => 6,
            'start_date' => '2026-05-05',
            'mode'       => 'new_purchase',
        ])->assertStatus(409)
            ->assertJsonPath('duplicate_course.id', (int) $duplicate->ID);

        $this->assertSame(2, StudentClass::where('StudentID', $student->id)->count());
    }

    public function test_renewal_preview_for_monthly_course_is_read_only_and_shows_invoice_impact(): void
    {
        $token = $this->createDirectorToken([1], 'director-preview-monthly@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'Charge'           => 4800,
            'Paid'             => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode'     => 'renew_monthly',
            'end_date' => '2026-05-31',
        ]);

        $res->assertOk()
            ->assertJsonPath('mode', 'renew_monthly')
            ->assertJsonPath('severity', 'ok')
            ->assertJsonPath('proposed_course.current_end_date', '2026-04-30')
            ->assertJsonPath('proposed_course.end_date', '2026-05-31')
            ->assertJsonPath('proposed_course.paid', 0)
            ->assertJsonPath('billing.amount_due', 4800)
            ->assertJsonPath('billing.invoice.billing_period', '2026-05')
            ->assertJsonPath('billing.invoice.will_create', true);

        $course->refresh();
        $this->assertSame('2026-04-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame(1, (int) $course->Paid);
        $this->assertSame(0, Invoice::where('StudentClassID', $course->ID)->count());
    }

    public function test_renewal_confirm_for_monthly_course_requires_fresh_preview_state(): void
    {
        $token = $this->createDirectorToken([1], 'director-confirm-stale@example.com');
        $student = $this->createStudent();

        $course = $this->createStudentClass($student->id, [
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'RemainingSessions' => 0,
            'StartDate'        => '2026-04-01',
            'EndDate'          => '2026-04-30',
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
            'Charge'           => 4800,
            'Paid'             => 1,
        ]);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode'     => 'renew_monthly',
            'end_date' => '2026-05-31',
        ])->json();

        $course->Paid = 0;
        $course->save();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $preview['preview_id'],
            'state_hash' => $preview['state_hash'],
            'mode'       => 'renew_monthly',
            'payload'    => ['end_date' => '2026-05-31'],
        ])->assertStatus(409)
            ->assertJsonPath('message', '課程狀態已變更，請重新預覽後再確認。');

        $course->refresh();
        $this->assertSame('2026-04-30', substr((string) $course->EndDate, 0, 10));
        $this->assertSame(0, Invoice::where('StudentClassID', $course->ID)->count());
    }

    public function test_renewal_confirm_for_session_course_creates_unpaid_new_batch_from_preview(): void
    {
        $token = $this->createDirectorToken([1], 'director-preview-batch@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'UsedSessions'      => 7,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'Charge'            => 4000,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
            'mode'       => 'purchase_batch',
            'sessions'   => 6,
            'start_date' => '2026-05-05',
        ])->assertOk()
            ->assertJsonPath('severity', 'ok')
            ->assertJsonPath('billing.amount_due', 3000)
            ->assertJsonPath('schedule.created_sessions', 6)
            ->json();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-confirm", [
            'preview_id' => $preview['preview_id'],
            'state_hash' => $preview['state_hash'],
            'mode'       => 'purchase_batch',
            'payload'    => [
                'sessions'   => 6,
                'start_date' => '2026-05-05',
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('mode', 'purchase_batch')
            ->assertJsonPath('new_course.session_count', 6)
            ->assertJsonPath('new_course.paid', 0)
            ->assertJsonPath('schedule.created_sessions', 6);

        $this->assertSame(2, StudentClass::where('StudentID', $student->id)->count());
        $this->assertSame(6, ClassSession::where('StudentClassID', $res->json('new_course.id'))->count());
    }

    public function test_renewal_confirm_rejects_reusing_same_purchase_batch_preview(): void
    {
        $token = $this->createDirectorToken([1], 'director-confirm-duplicate@example.com');
        $student = $this->createStudent();

        $source = $this->createStudentClass($student->id, [
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 1,
            'UsedSessions'      => 7,
            'Paid'              => 1,
            'Rate'              => 500,
            'SessionDuration'   => 120,
            'Charge'            => 4000,
            'StartDate'         => '2026-03-01',
            'week'              => 2,
            'time'              => '20:00:00',
        ]);

        $preview = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-preview", [
            'mode'       => 'purchase_batch',
            'sessions'   => 6,
            'start_date' => '2026-05-05',
        ])->assertOk()
            ->json();

        $payload = [
            'preview_id' => $preview['preview_id'],
            'state_hash' => $preview['state_hash'],
            'mode'       => 'purchase_batch',
            'payload'    => [
                'sessions'   => 6,
                'start_date' => '2026-05-05',
            ],
        ];

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-confirm", $payload)
            ->assertCreated();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/renewal-confirm", $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', '課程狀態已變更，請重新預覽後再確認。');

        $this->assertSame(2, StudentClass::where('StudentID', $student->id)->count());
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name'      => '主任測試',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name'         => '月結續約學生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 99,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => now()->toDateString(),
            'EndDate'          => null,
            'TotalHours'       => 20,
            'Charge'           => 0,
            'Paid'             => 0,
            'Rate'             => 0,
            'MDate'            => now(),
            'Stop'             => 0,
            'ScheduleMode'     => 'count',
            'SessionCount'     => 8,
            'SessionDuration'  => 60,
            'RemainingSessions' => 8,
            'ClassType'        => 'one_on_one',
            'UsedSessions'     => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }

    /**
     * @return array<int, string>
     */
    private function scheduledSessionDates(StudentClass $course): array
    {
        return ClassSession::where('StudentClassID', $course->ID)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->pluck('SessionDate')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();
    }
}
