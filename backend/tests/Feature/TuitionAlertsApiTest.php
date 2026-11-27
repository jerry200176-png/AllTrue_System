<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
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

class TuitionAlertsApiTest extends TestCase
{
    use RefreshDatabase;

    /** #4144: branch_id outside the director's campuses is refused, never widened or silently emptied. */
    public function test_branch_outside_the_directors_campuses_is_forbidden(): void
    {
        $token = $this->createDirectorToken([1], 'director-tuition-cross-campus@example.com');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=2')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk();
    }

    public function test_tutoring_courses_are_not_tuition_alert_candidates(): void
    {
        $token = $this->createDirectorToken([1], 'director-tuition-tutoring@example.com');
        $student = Student::create([
            'name' => '免費輔導提醒排除', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $count = $this->createCountModeClass($student->id, [
            'ClassType' => 'tutoring', 'Rate' => 0, 'Charge' => 0, 'RemainingSessions' => 0,
        ]);
        $date = $this->createMonthlyClass($student->id, [
            'ClassType' => 'tutoring', 'Rate' => 0, 'Charge' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/alerts/tuition?branch_id=1');
        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertFalse($ids->contains((int) $count->ID));
        $this->assertFalse($ids->contains((int) $date->ID));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_count_mode_includes_zero_remaining_when_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '零堂已繳',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $course->ID, $ids);

        // in-app #554: the row carries the exact minute balance beside the rounded lesson count, so the list can tell
        // "nothing left" from "part of a lesson left" (both read as 0 lessons).
        \Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $course->ID)->update(['RemainingMinutes' => 30]);
        $row = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $course->ID);
        $this->assertSame(0, $row['remaining_sessions']);
        $this->assertSame(30, $row['remaining_minutes']);
    }

    public function test_monthly_unpaid_excluded_when_five_or_more_days_before_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-09', 'Asia/Taipei')); // 6 days before Apr-15, satisfies daysLeft > 5

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結五天外',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids);
    }

    public function test_monthly_unpaid_included_when_less_than_five_days_before_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-12', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結三天內',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['alert_type']);
        $this->assertSame(3, (int) $row['days_until_settlement']);
        $this->assertSame('2026-04-15', $row['due_date']);
    }

    public function test_monthly_unpaid_included_when_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-18', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結逾期',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['alert_type']);
        $this->assertSame(-3, (int) $row['days_until_settlement']);
    }

    public function test_monthly_alert_uses_future_invoice_due_date_when_pre_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-18', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結未來期',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
            'StartDate' => '2026-05-01',
            'EndDate' => '2026-05-31',
        ]);

        Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-18',
            'DueDate' => '2026-05-15',
            'TotalAmount' => 5000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-05',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids, '未來月份帳單不應被當成本月逾期');
    }

    public function test_monthly_alert_returns_period_invoice_when_due_window_arrives(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結應繳期',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
            'StartDate' => '2026-05-01',
            'EndDate' => '2026-05-31',
        ]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-18',
            'DueDate' => '2026-05-15',
            'TotalAmount' => 5000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-05',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('2026-05-15', $row['due_date']);
        $this->assertSame(3, (int) $row['days_until_settlement']);
        $this->assertSame($invoice->id, $row['invoice_id']);
        $this->assertSame('2026-05', $row['billing_period']);
    }

    /** in-app #589: a paid monthly course that ended months ago has no next settlement; an unpaid one still shows. */
    public function test_monthly_paid_excluded_after_end_date_but_unpaid_still_listed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結已結束',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $endedPaid = $this->createMonthlyClass($student->id, [
            'settlement_day' => 22,
            'Paid' => 1,
            'StartDate' => '2026-04-12',
            'EndDate' => '2026-04-30',
        ]);
        $endedUnpaid = $this->createMonthlyClass($student->id, [
            'settlement_day' => 22,
            'Paid' => 0,
            'StartDate' => '2026-04-12',
            'EndDate' => '2026-09-14',
        ]);
        $runningPaid = $this->createMonthlyClass($student->id, [
            'settlement_day' => 22,
            'Paid' => 1,
            'StartDate' => '2026-04-12',
            'EndDate' => '2026-12-31',
        ]);

        // Flag says paid, but the bill has no payment behind it: the billing verdict still owes, so it must stay listed.
        $endedFlagOnly = $this->createMonthlyClass($student->id, [
            'settlement_day' => 22,
            'Paid' => 1,
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-09-14',
        ]);
        Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $endedFlagOnly->ID,
            'IssueDate' => '2026-08-01',
            'DueDate' => '2026-08-22',
            'TotalAmount' => 5000,
            'PaidAmount' => 5000,
            'Status' => 'paid',
            'billing_period' => '2026-08',
        ]);
        $paidInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $endedPaid->ID,
            'IssueDate' => '2026-04-12',
            'DueDate' => '2026-04-22',
            'TotalAmount' => 5000,
            'PaidAmount' => 5000,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);
        Payment::create(['InvoiceID' => $paidInvoice->id, 'Amount' => 5000, 'Method' => 'cash', 'PaidAt' => '2026-04-12']);

        $legacyOnly = $this->createMonthlyClass($student->id, [
            'settlement_day' => 22,
            'Paid' => 1,
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-08-31',
        ]);
        $foreignInvoiceCourse = $this->createMonthlyClass($student->id, [
            'settlement_day' => 22,
            'Paid' => 1,
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-08-31',
        ]);
        $otherStudent = Student::create(['name' => '另一位學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $foreignInvoice = Invoice::create([
            'StudentID' => $otherStudent->id,
            'StudentClassID' => $foreignInvoiceCourse->ID,
            'IssueDate' => '2026-08-01',
            'DueDate' => '2026-08-22',
            'TotalAmount' => 5000,
            'Status' => 'paid',
            'billing_period' => '2026-08',
        ]);
        Payment::create(['InvoiceID' => $foreignInvoice->id, 'Amount' => 5000, 'Method' => 'cash', 'PaidAt' => '2026-08-12']);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $rows = collect($res->json());
        $this->assertNotNull($rows->firstWhere('id', $endedFlagOnly->ID), 'ended, flag paid, nothing applied: still listed');
        $this->assertNull($rows->firstWhere('id', $endedPaid->ID), 'ended and paid: no next settlement');
        $this->assertNotNull($rows->firstWhere('id', $legacyOnly->ID), 'ended with only a legacy paid flag and no invoice must stay listed');
        $this->assertNotNull($rows->firstWhere('id', $foreignInvoiceCourse->ID), 'a mislinked invoice cannot settle a different student’s course');
        $this->assertSame('monthly_due_soon', $rows->firstWhere('id', $endedUnpaid->ID)['alert_type'] ?? null, 'ended but unpaid stays listed');
        $this->assertSame('monthly_due_soon', $rows->firstWhere('id', $runningPaid->ID)['alert_type'] ?? null, 'still running: unchanged');

        PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $endedPaid->ID,
            'reported_by_name' => $student->name, 'payment_date' => Carbon::today(),
            'payment_method' => 'cash', 'reported_amount' => 5000, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'ended-monthly-awaiting-review'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);
        $pendingRows = collect($this->withToken($token)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $this->assertSame('pending_report', $pendingRows->firstWhere('id', $endedPaid->ID)['payment_status'] ?? null);
    }

    /**
     * in-app #603/#605/#606: an ended monthly contract whose only bill is fully paid came back every month around its
     * settlement day asking for a new bill, because the period engine could not line the old bill up with the lessons
     * (bill without a period; contract starting at the end of the previous month). Both shapes are from production.
     */
    public function test_ended_monthly_with_paid_bill_and_lessons_has_no_settlement_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '月結已結束有上課', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $terms = ['settlement_day' => 30, 'Paid' => 1, 'Charge' => 4400, 'Rate' => 1100, 'monthly_sessions' => 4, 'ClassType' => 'one_on_three'];

        $noPeriod = $this->createMonthlyClass($student->id, $terms + ['StartDate' => '2026-04-04', 'EndDate' => '2026-04-25', 'PayDate' => '2026-05-05']);
        foreach (['2026-04-04' => 'attended', '2026-04-11' => 'completed', '2026-04-18' => 'leave_adjusted', '2026-04-25' => 'leave_adjusted'] as $date => $status) {
            ClassSession::create(['StudentClassID' => $noPeriod->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $bill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $noPeriod->ID, 'IssueDate' => '2026-05-05',
            'TotalAmount' => 2200, 'PaidAmount' => 2200, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 2200, 'Method' => 'transfer', 'PaidAt' => '2026-05-03']);

        $lateStart = $this->createMonthlyClass($student->id, $terms + ['StartDate' => '2026-04-29', 'EndDate' => '2026-05-31', 'PayDate' => '2026-07-10']);
        foreach (['2026-05-06' => 'attended', '2026-05-13' => 'attended', '2026-05-20' => 'attended', '2026-05-27' => 'attended', '2026-05-09' => 'cancelled', '2026-05-16' => 'cancelled'] as $date => $status) {
            ClassSession::create(['StudentClassID' => $lateStart->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        $bill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $lateStart->ID, 'IssueDate' => '2026-05-05', 'DueDate' => '2026-04-30',
            'TotalAmount' => 4400, 'PaidAmount' => 4400, 'Status' => 'paid', 'billing_period' => '2026-05']);
        InvoiceItem::create(['InvoiceID' => $bill->id, 'Description' => 'tuition', 'Amount' => 4400]);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 4400, 'Method' => 'transfer', 'PaidAt' => '2026-06-05']);

        // Same shape, but half of the bill is still owed: this one must stay.
        $owing = $this->createMonthlyClass($student->id, $terms + ['StartDate' => '2026-04-04', 'EndDate' => '2026-04-25']);
        $bill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $owing->ID, 'IssueDate' => '2026-05-05',
            'TotalAmount' => 4400, 'PaidAmount' => 2200, 'Status' => 'partial']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 2200, 'Method' => 'transfer', 'PaidAt' => '2026-05-03']);

        $rows = collect($this->withToken($token)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());

        $this->assertNull($rows->firstWhere('id', $noPeriod->ID), 'paid bill without a period: nothing left to bill');
        $this->assertNull($rows->firstWhere('id', $lateStart->ID), 'paid bill, contract started at the end of the previous month');
        $this->assertNotNull($rows->firstWhere('id', $owing->ID), 'a bill with a balance stays listed');
    }

    public function test_monthly_paid_included_when_next_due_within_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結已繳下次近',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['alert_type']);
        $this->assertSame(1, (int) $row['paid']);
        $this->assertSame('2026-05-15', $row['due_date']);
        $this->assertSame(3, (int) $row['days_until_settlement']);
    }

    public function test_tuition_alert_returns_last_paid_at_when_payment_exists(): void
    {
        $token = $this->createDirectorToken([1], 'paid-at-test@example.com');
        $student = Student::create([
            'name' => '已繳費學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 2,
        ]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000,
            'PaidAmount' => 5000,
            'Status' => 'paid',
        ]);

        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 5000,
            'PaidAt' => '2026-04-05 14:30:00',
            'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('2026-04-05', $row['last_paid_at']);
    }

    public function test_tuition_alert_returns_null_last_paid_at_when_no_payment(): void
    {
        $token = $this->createDirectorToken([1], 'no-paid-at@example.com');
        $student = Student::create([
            'name' => '未繳費學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0,
            'RemainingSessions' => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertNull($row['last_paid_at']);
    }

    public function test_tuition_alert_returns_latest_paid_at_with_multiple_payments(): void
    {
        $token = $this->createDirectorToken([1], 'multi-pay@example.com');
        $student = Student::create([
            'name' => '多次繳費',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 0,
        ]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 10000,
            'PaidAmount' => 10000,
            'Status' => 'paid',
        ]);

        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 5000,
            'PaidAt' => '2026-04-01 10:00:00',
            'Method' => 'cash',
        ]);

        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 5000,
            'PaidAt' => '2026-04-10 16:00:00',
            'Method' => 'transfer',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('2026-04-10', $row['last_paid_at']);
    }

    // ── payment_status tests ──────────────────────────────────────

    public function test_payment_status_unpaid_for_count_mode_no_payment(): void
    {
        $token = $this->createDirectorToken([1], 'ps-unpaid@example.com');
        $student = Student::create([
            'name' => '未繳費堂數制',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'Charge' => 8800,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('unpaid', $row['payment_status']);
        $this->assertSame(8800, $row['charge']);
        $this->assertSame(0, $row['paid_amount']);
        $this->assertSame(8800, $row['outstanding']);
        $this->assertSame('unbilled', $row['payable_status']);
        $this->assertTrue($row['can_record_unbilled_count']);
        $this->assertNull($row['payable_amount']);
        $this->assertSame(8800, $row['estimated_amount']);
        $this->assertNull($row['latest_payment_report_id']);
    }

    public function test_an_estimate_without_an_agreed_count_charge_does_not_offer_direct_record(): void
    {
        $token = $this->createDirectorToken([1], 'unconfirmed-fee@example.com');
        $student = Student::create([
            'name' => '待核對堂數制費用', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'SessionCount' => 5, 'Rate' => 1000, 'Charge' => 0,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk();
        $row = collect($response->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('unbilled', $row['payable_status']);
        $this->assertSame(5000, $row['estimated_amount']);
        $this->assertFalse($row['can_record_unbilled_count']);
    }

    public function test_payment_status_partial_when_invoice_partially_paid(): void
    {
        $token = $this->createDirectorToken([1], 'ps-partial@example.com');
        $student = Student::create([
            'name' => '部分付款',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 3, 'Charge' => 10000,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01', 'TotalAmount' => 10000, 'PaidAmount' => 3000, 'Status' => 'partial',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 3000, 'PaidAt' => '2026-04-05', 'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('partial', $row['payment_status']);
        $this->assertSame(10000, $row['charge']);
        $this->assertSame(3000, $row['paid_amount']);
        $this->assertSame(7000, $row['outstanding']);
        $this->assertSame('invoiced', $row['payable_status']);
        $this->assertSame(10000, $row['payable_amount']);
        $this->assertSame(7000, $row['payable_outstanding']);
        $this->assertSame('2026-04', $row['billing_period']);
    }

    /**
     * Director-reported live discrepancy (2026-08-06): a course settled via a
     * recorded invoice payment (paid_amount === charge) but whose raw Paid
     * flag was never manually flipped showed "已繳費" in course management
     * (Paid=1 OR has invoice payment) but "未繳費" here (Paid flag only).
     */
    public function test_payment_status_paid_when_invoice_fully_paid_without_paid_flag(): void
    {
        $token = $this->createDirectorToken([1], 'ps-invoice-settled@example.com');
        $student = Student::create([
            'name' => '帳單結清未切旗標',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'Charge' => 8000,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-06-01', 'TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 8000, 'PaidAt' => '2026-06-02', 'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame(8000, $row['charge']);
        $this->assertSame(8000, $row['paid_amount']);
        $this->assertSame(0, $row['outstanding']);
    }

    /**
     * Same scenario as above but with 0 remaining sessions (the exact shape
     * of the reported course): must resolve to the renewal-reminder state,
     * never plain "unpaid" — a fully-settled, exhausted course is still due
     * for a renewal nudge, but it must not read as if nothing was paid.
     */
    public function test_payment_status_renew_needed_not_unpaid_when_invoice_fully_paid_and_zero_remaining(): void
    {
        $token = $this->createDirectorToken([1], 'ps-invoice-settled-zero@example.com');
        $student = Student::create([
            'name' => '帳單結清零堂數',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 0, 'Charge' => 8000,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-06-01', 'TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 8000, 'PaidAt' => '2026-06-02', 'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('renew_needed', $row['payment_status']);
        $this->assertNotSame('unpaid', $row['payment_status']);
        $this->assertSame(0, $row['outstanding']);
    }

    /**
     * Count-mode CoursePackage (multi-subject shared session pool) equivalent of
     * test_payment_status_paid_when_invoice_fully_paid_without_paid_flag — this path
     * (AlertController::computePackageCountPaymentStatus() + the $countPkgAlerts row's
     * own 'paid'/'outstanding' fields) had no regression coverage at all before this
     * PR's AlertController::isFullyPaid() consolidation, despite reimplementing the
     * exact same "Paid OR fully-covering invoice payment" predicate independently.
     */
    public function test_package_count_mode_payment_status_paid_when_invoice_fully_paid_without_paid_flag(): void
    {
        $token = $this->createDirectorToken([1], 'ps-pkg-invoice-settled@example.com');
        $student = Student::create([
            'name' => '方案帳單結清未切旗標',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '方案結清測試',
            'total_sessions' => 8, 'remaining_sessions' => 5, 'used_sessions' => 3,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $anchor = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'SessionCount' => 8,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 8, 'PackageName' => $pkg->name,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $anchor->ID,
            'IssueDate' => '2026-06-01', 'TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 8000, 'PaidAt' => '2026-06-02', 'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('package_id', $pkg->id);
        $this->assertNotNull($row);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertTrue($row['paid']);
        $this->assertSame(0, $row['outstanding']);
    }

    /**
     * Same package path, but the invoice covers only part of the charge — must
     * stay 'partial' (both in payment_status and the row's own 'paid'/'outstanding'
     * fields), not be misclassified as fully paid by the "any payment exists" trap
     * this codebase's other payment-status implementations fall into (F7 family).
     */
    public function test_package_count_mode_payment_status_partial_when_invoice_partially_paid(): void
    {
        $token = $this->createDirectorToken([1], 'ps-pkg-invoice-partial@example.com');
        $student = Student::create([
            'name' => '方案部分付款',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '方案部分付款測試',
            'total_sessions' => 8, 'remaining_sessions' => 5, 'used_sessions' => 3,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $anchor = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'SessionCount' => 8,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 8, 'PackageName' => $pkg->name,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $anchor->ID,
            'IssueDate' => '2026-06-01', 'TotalAmount' => 8000, 'PaidAmount' => 3000, 'Status' => 'partial',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 3000, 'PaidAt' => '2026-06-02', 'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('package_id', $pkg->id);
        $this->assertNotNull($row);
        $this->assertSame('partial', $row['payment_status']);
        $this->assertFalse($row['paid']);
        $this->assertSame(5000, $row['outstanding']);
    }

    public function test_payment_status_pending_report_when_has_pending_report(): void
    {
        $token = $this->createDirectorToken([1], 'ps-pending@example.com');
        $student = Student::create([
            'name' => '待核帳',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'Charge' => 8800,
        ]);
        $report = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            'reported_amount' => 8800, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'ps-pending-test'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('pending_report', $row['payment_status']);
        $this->assertSame($report->id, $row['latest_payment_report_id']);
    }

    public function test_payment_status_renew_needed_for_paid_low_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'ps-renew@example.com');
        $student = Student::create([
            'name' => '已繳低堂',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 1, 'Charge' => 8800,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('renew_needed', $row['payment_status']);
        // #4114 (Founder Q3): Paid=1 with no Payment is the legacy flag alone; still a renewal row, never owed.
        $this->assertTrue($row['legacy_paid_flag_only']);
    }

    /** #4114 (Founder Q3): flag-only paid rows are marked for 需核對; status, amounts and reminders are unchanged. */
    public function test_legacy_paid_flag_only_marks_paid_rows_without_a_payment_record(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-flag@example.com');
        $student = Student::create(['name' => 'S4114', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $low = ['Paid' => 1, 'RemainingSessions' => 1, 'Charge' => 5000];
        $receipt = fn (StudentClass $c, string $status, int $amount) => Payment::create([
            'InvoiceID' => Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-04-01',
                'TotalAmount' => $amount, 'PaidAmount' => $amount, 'Status' => $status])->id,
            'Amount' => $amount, 'PaidAt' => '2026-04-05', 'Method' => 'cash']);
        $flagOnly = $this->createCountModeClass($student->id, $low + ['SubjectID' => 6]); // own subject: the package courses below must not count as its renewal (#495)
        $paid = $this->createCountModeClass($student->id, $low + ['SubjectID' => 2]);
        $receipt($paid, 'paid', 5000);
        $voidedBill = $this->createCountModeClass($student->id, $low + ['SubjectID' => 3]);
        $receipt($voidedBill, 'void', 5000);
        $unpaid = $this->createCountModeClass($student->id, ['Paid' => 0, 'SubjectID' => 4]);
        $package = function (bool $withReceipt) use ($student, $receipt) {
            $pkg = CoursePackage::create(['student_id' => $student->id, 'campus_id' => 1, 'name' => 'P' . (int) $withReceipt,
                'total_sessions' => 8, 'remaining_sessions' => 1, 'used_sessions' => 7, 'rate' => 1000, 'rate_unit' => 'session',
                'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true]);
            $this->createCountModeClass($student->id, ['RemainingSessions' => 1, 'SessionCount' => 8, 'PackageID' => $pkg->id]);
            $member = $this->createCountModeClass($student->id, ['RemainingSessions' => 1, 'SessionCount' => 8, 'PackageID' => $pkg->id, 'SubjectID' => 5]);
            if ($withReceipt) {
                $receipt($member, 'paid', 8000); // a package bill on a non-anchor member still counts
            }

            return $pkg->id;
        };
        $pkgFlagOnly = $package(false);
        $pkgPaid = $package(true);

        $rows = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $row = fn ($id) => $rows->first(fn ($r) => (int) $r['id'] === (int) $id && !isset($r['package_id']));
        $pkg = fn ($id) => $rows->firstWhere('package_id', $id);

        $this->assertSame(['renew_needed', 0, true], [$row($flagOnly->ID)['payment_status'], (int) $row($flagOnly->ID)['outstanding'], $row($flagOnly->ID)['legacy_paid_flag_only']]);
        $this->assertFalse($row($paid->ID)['legacy_paid_flag_only'], 'a standing receipt is a real 已收');
        $this->assertTrue($row($voidedBill->ID)['legacy_paid_flag_only'], 'a receipt on a void bill is not a payment record');
        $this->assertSame(['unpaid', false], [$row($unpaid->ID)['payment_status'], $row($unpaid->ID)['legacy_paid_flag_only']]);
        $this->assertSame(['renew_needed', true], [$pkg($pkgFlagOnly)['payment_status'], $pkg($pkgFlagOnly)['legacy_paid_flag_only']]);
        $this->assertSame(['renew_needed', false], [$pkg($pkgPaid)['payment_status'], $pkg($pkgPaid)['legacy_paid_flag_only']]);
    }

    /** in-app #428 (#4330): a trial paid at creation (Paid=1, no invoice by design) is not legacy data: no 需核對. */
    public function test_legacy_paid_flag_only_skips_trial_paid_at_creation(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-flag-trial@example.com');
        $student = Student::create(['name' => 'S428', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $shape = ['Paid' => 1, 'PayDate' => now()->toDateString(), 'RemainingSessions' => 1, 'SessionCount' => 1, 'Charge' => 500];
        $trial = $this->createCountModeClass($student->id, $shape + ['ClassType' => 'trial']);
        $regular = $this->createCountModeClass($student->id, $shape + ['SubjectID' => 2]);
        // create-multi-subject trial package: package and members carry class_type=trial and paid/paid_at, no invoice.
        $trialPkg = CoursePackage::create(['student_id' => $student->id, 'campus_id' => 1, 'name' => 'T428', 'billing_mode' => 'count',
            'total_sessions' => 1, 'remaining_sessions' => 1, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'trial', 'paid' => true, 'paid_at' => now()->toDateString(), 'stop' => false, 'enabled' => true]);
        foreach ([3, 4] as $subjectId) {
            $this->createCountModeClass($student->id, $shape + ['ClassType' => 'trial', 'PackageID' => $trialPkg->id, 'SubjectID' => $subjectId]);
        }

        $rows = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $row = fn ($id) => $rows->first(fn ($r) => (int) $r['id'] === (int) $id && !isset($r['package_id']));

        $this->assertNotNull($row($trial->ID), 'the low-remaining trial is listed (shape under test)');
        $this->assertFalse($row($trial->ID)['legacy_paid_flag_only']);
        $this->assertTrue($row($regular->ID)['legacy_paid_flag_only'], '#4174 unchanged for a regular course');
        $pkgRow = $rows->firstWhere('package_id', $trialPkg->id);
        $this->assertNotNull($pkgRow, 'the low-remaining trial package is listed (shape under test)');
        $this->assertFalse($pkgRow['legacy_paid_flag_only'], 'a create-multi-subject trial package is not legacy data either');
    }

    /**
     * in-app #428 review: only a trial that carries its payment date is exempt. A trial course marked paid without PayDate,
     * and a trial package marked paid through PUT /course-packages/{id} without paid_at, stay 需核對 (as before #428).
     */
    public function test_legacy_paid_flag_only_requires_trial_payment_date(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-flag-trial-nodate@example.com');
        $auth = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $student = Student::create(['name' => 'S428b', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $shape = ['RemainingSessions' => 1, 'SessionCount' => 1, 'Charge' => 500];
        $undatedTrial = $this->createCountModeClass($student->id, $shape + ['ClassType' => 'trial', 'Paid' => 1]);
        $trialPkg = CoursePackage::create(['student_id' => $student->id, 'campus_id' => 1, 'name' => 'T428b', 'billing_mode' => 'count',
            'total_sessions' => 1, 'remaining_sessions' => 1, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'trial', 'paid' => false, 'stop' => false, 'enabled' => true]);
        foreach ([3, 4] as $subjectId) {
            $this->createCountModeClass($student->id, $shape + ['ClassType' => 'trial', 'PackageID' => $trialPkg->id, 'SubjectID' => $subjectId]);
        }
        $this->withHeaders($auth)->putJson("/api/v1/course-packages/{$trialPkg->id}", ['paid' => true])->assertOk();
        $this->assertSame([true, null], [(bool) $trialPkg->fresh()->paid, $trialPkg->fresh()->paid_at], 'shape under test: paid without paid_at');

        $rows = collect($this->withHeaders($auth)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $classRow = $rows->first(fn ($r) => (int) $r['id'] === (int) $undatedTrial->ID && !isset($r['package_id']));
        $pkgRow = $rows->firstWhere('package_id', $trialPkg->id);
        $this->assertNotNull($classRow, 'the low-remaining undated trial is listed (shape under test)');
        $this->assertTrue($classRow['legacy_paid_flag_only'], 'a trial marked paid without PayDate stays 需核對');
        $this->assertNotNull($pkgRow, 'the low-remaining trial package is listed (shape under test)');
        $this->assertTrue($pkgRow['legacy_paid_flag_only'], 'a trial package marked paid without paid_at stays 需核對');

        $list = collect($this->withHeaders($auth)->getJson('/api/v1/student-classes?branch_id=1')->assertOk()->json('data'));
        $this->assertTrue($list->firstWhere('ID', $undatedTrial->ID)['legacy_paid_flag_only'], 'course list agrees for the undated trial');
    }

    /**
     * in-app #428 review: a regular course bound to a paid trial package (POST /course-packages/{id}/bind-courses) takes its
     * paid state from the package, so the course list follows the package's trial type and paid_at, like the tuition row.
     */
    public function test_regular_course_bound_to_trial_package_matches_tuition_package_row(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-flag-trial-bind@example.com');
        $auth = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $student = Student::create(['name' => 'S428c', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $pkgFields = ['student_id' => $student->id, 'campus_id' => 1, 'billing_mode' => 'count', 'total_sessions' => 1,
            'remaining_sessions' => 1, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'trial',
            'paid' => true, 'stop' => false, 'enabled' => true];
        $datedPkg = CoursePackage::create($pkgFields + ['name' => 'T428c', 'paid_at' => now()->toDateString()]);
        $undatedPkg = CoursePackage::create($pkgFields + ['name' => 'T428d']);
        $shape = ['RemainingSessions' => 1, 'SessionCount' => 1, 'Charge' => 500, 'Paid' => 0, 'PayDate' => null];
        $boundDated = $this->createCountModeClass($student->id, $shape + ['SubjectID' => 2]);
        $boundUndated = $this->createCountModeClass($student->id, $shape + ['SubjectID' => 3]);
        foreach ([[$datedPkg, $boundDated], [$undatedPkg, $boundUndated]] as [$pkg, $course]) {
            $this->withHeaders($auth)->postJson("/api/v1/course-packages/{$pkg->id}/bind-courses", ['student_class_ids' => [$course->ID]])->assertOk();
            $this->assertSame([(int) $pkg->id, 'one_on_one'], [(int) $course->fresh()->PackageID, $course->fresh()->ClassType], 'bind keeps the member type');
        }

        $rows = collect($this->withHeaders($auth)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $list = collect($this->withHeaders($auth)->getJson('/api/v1/student-classes?branch_id=1')->assertOk()->json('data'));
        foreach ([[$datedPkg, $boundDated, false], [$undatedPkg, $boundUndated, true]] as [$pkg, $course, $expected]) {
            $pkgRow = $rows->firstWhere('package_id', $pkg->id);
            $this->assertNotNull($pkgRow, 'the low-remaining trial package is listed (shape under test)');
            $this->assertSame($expected, $pkgRow['legacy_paid_flag_only']);
            $this->assertSame($pkgRow['legacy_paid_flag_only'], $list->firstWhere('ID', $course->ID)['legacy_paid_flag_only'],
                'course list and tuition alerts agree for the bound member');
        }
    }

    /**
     * #4339 review: a stale PackageID to a dated trial package of another student, or of the same student on another campus,
     * never clears 需核對: the trial exemption reads only a package owned by the course's student on the student's campus.
     */
    public function test_foreign_trial_package_link_does_not_clear_legacy_flag(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-flag-trial-foreign@example.com');
        $auth = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $mk = fn (string $name) => Student::create(['name' => $name, 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        [$student, $other] = [$mk('S4339a'), $mk('S4339b')];
        $pkg = fn (int $studentId, int $campusId, string $name) => CoursePackage::create(['student_id' => $studentId, 'campus_id' => $campusId,
            'name' => $name, 'billing_mode' => 'count', 'total_sessions' => 1, 'remaining_sessions' => 1, 'used_sessions' => 0, 'rate' => 500,
            'rate_unit' => 'session', 'class_type' => 'trial', 'paid' => true, 'paid_at' => now()->toDateString(), 'stop' => false, 'enabled' => true]);
        $shape = ['RemainingSessions' => 1, 'SessionCount' => 1, 'Charge' => 500, 'Paid' => 0, 'PayDate' => null, 'ClassType' => 'trial'];
        $otherStudents = $this->createCountModeClass($student->id, $shape + ['PackageID' => $pkg($other->id, 1, 'F1')->id, 'SubjectID' => 2]);
        $otherCampus = $this->createCountModeClass($student->id, $shape + ['PackageID' => $pkg($student->id, 2, 'F2')->id, 'SubjectID' => 3]);
        $own = $this->createCountModeClass($student->id, $shape + ['PackageID' => $pkg($student->id, 1, 'F3')->id, 'SubjectID' => 4]);

        $list = collect($this->withHeaders($auth)->getJson('/api/v1/student-classes?branch_id=1')->assertOk()->json('data'));
        $this->assertTrue($list->firstWhere('ID', $otherStudents->ID)['legacy_paid_flag_only'], "another student's package stays 需核對");
        $this->assertTrue($list->firstWhere('ID', $otherCampus->ID)['legacy_paid_flag_only'], "another campus's package stays 需核對");
        $this->assertFalse($list->firstWhere('ID', $own->ID)['legacy_paid_flag_only'], 'control: the own dated trial package is exempt');
        // The tuition classifier and the ledger call the same helper on the loaded course (no caller-supplied package).
        $this->assertSame([false, false, true], [$otherStudents->fresh()->isTrialFeeRecordedByDate(),
            $otherCampus->fresh()->isTrialFeeRecordedByDate(), $own->fresh()->isTrialFeeRecordedByDate()]);
    }

    public function test_payment_status_monthly_due_soon_for_date_mode_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'ps-monthly@example.com');
        $student = Student::create([
            'name' => '月結已繳',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15, 'Paid' => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['payment_status']);
        $this->assertTrue($row['legacy_paid_flag_only'], '#4114: Paid=1 without a Payment reads 需核對, not 已收');
    }

    public function test_payment_status_paid_for_count_mode_fully_paid_no_low_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'ps-paid@example.com');
        $student = Student::create([
            'name' => '全額已繳',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 0, 'Charge' => 8800,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('renew_needed', $row['payment_status']);
    }

    // #959/G-009: course-list payment_status treats a fully-settled non-void Invoice
    // payment as paid even when StudentClass.Paid was never flipped to 1 — the tuition
    // alert path must agree, or a course looks paid on one screen and unpaid on another.
    public function test_payment_status_paid_when_settled_via_invoice_despite_paid_flag_zero(): void
    {
        $token = $this->createDirectorToken([1], 'ps-invoice-settled@example.com');
        $student = Student::create([
            'name' => '發票已結清但Paid未更新',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 5, 'Charge' => 8000,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01', 'TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 8000, 'PaidAt' => '2026-04-05', 'Method' => 'cash',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertNotSame('unpaid', $row['payment_status']);
        $this->assertSame(0, $row['outstanding'], 'fully invoice-settled course must not show outstanding balance');
    }

    public function test_has_newer_course_true_when_same_subject_active_course_exists(): void
    {
        $token = $this->createDirectorToken([1], 'newer-true@example.com');
        $student = Student::create([
            'name' => '有新課程', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $oldCourse = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 0, 'Charge' => 8000, 'SubjectID' => 66,
        ]);
        $newCourse = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 8, 'Charge' => 8000, 'SubjectID' => 66,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $oldCourse->ID);
        if ($row !== null) {
            $this->assertTrue($row['has_newer_course']);
            $this->assertSame((int) $newCourse->ID, $row['newer_course_id']);
        }
    }

    public function test_has_newer_course_false_when_no_same_subject_course(): void
    {
        $token = $this->createDirectorToken([1], 'newer-false@example.com');
        $student = Student::create([
            'name' => '無新課程', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 0, 'Charge' => 8000, 'SubjectID' => 77,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertFalse($row['has_newer_course']);
        $this->assertNull($row['newer_course_id']);
    }

    // #1100/FD-3: leave-cascade extension of A's EndDate must never silently shift B's
    // StartDate — this only asserts the read-only overlap flag exists and is correct;
    // it does not (and must not) mutate either course's dates.
    public function test_newer_course_overlap_true_when_extended_end_date_reaches_newer_course_start(): void
    {
        $token = $this->createDirectorToken([1], 'overlap-true@example.com');
        $student = Student::create([
            'name' => 'A期順延重疊B期', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        // Paid=0 so this row survives suppressRenewedLowSessionAlerts(), which only
        // hides alert_type='low_sessions' rows once a same-subject renewal exists —
        // it does not suppress 'unpaid' rows, so this stays a realistic director-visible case.
        $courseA = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 0, 'Charge' => 8000, 'SubjectID' => 88,
            'EndDate' => '2026-08-08',
        ]);
        $courseB = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 8, 'Charge' => 8000, 'SubjectID' => 88,
            'StartDate' => '2026-08-05',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $courseA->ID);
        $this->assertNotNull($row);
        $this->assertTrue($row['has_newer_course']);
        $this->assertTrue($row['newer_course_overlap']);
        $this->assertSame('2026-08-08', $row['current_course_end_date']);

        $this->assertSame('2026-08-08', substr((string) StudentClass::find($courseA->ID)->EndDate, 0, 10), 'read-only flag must not mutate A EndDate');
        $this->assertSame('2026-08-05', substr((string) StudentClass::find($courseB->ID)->StartDate, 0, 10), 'read-only flag must not mutate B StartDate');
    }

    public function test_newer_course_overlap_false_when_end_date_precedes_newer_course_start(): void
    {
        $token = $this->createDirectorToken([1], 'overlap-false@example.com');
        $student = Student::create([
            'name' => 'A期不重疊B期', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $courseA = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 0, 'Charge' => 8000, 'SubjectID' => 89,
            'EndDate' => '2026-08-01',
        ]);
        $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 8, 'Charge' => 8000, 'SubjectID' => 89,
            'StartDate' => '2026-08-05',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $courseA->ID);
        $this->assertNotNull($row);
        $this->assertTrue($row['has_newer_course']);
        $this->assertFalse($row['newer_course_overlap']);
    }

    // Codex P2 on #4887: a later free tutoring course is not a renewal, so the old course still needs one.
    public function test_later_tutoring_course_does_not_clear_renew_needed(): void
    {
        $token = $this->createDirectorToken([1], 'tutoring-later@example.com');
        $student = Student::create([
            'name' => 'T495b', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $old = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 1, 'Charge' => 8000, 'SubjectID' => 96]);
        $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 1, 'Charge' => 0, 'SubjectID' => 96, 'ClassType' => 'tutoring']);

        $row = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $old->ID);
        $this->assertNotNull($row);
        $this->assertFalse($row['renewed_by_newer_course']);
        $this->assertSame('renew_needed', $row['payment_status']);
    }

    // Codex P2 on #4887: a later free trial is not a renewal either.
    public function test_later_free_trial_does_not_clear_renew_needed(): void
    {
        $token = $this->createDirectorToken([1], 'trial-later@example.com');
        $student = Student::create([
            'name' => 'T495d', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $old = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 1, 'Charge' => 8000, 'SubjectID' => 98]);
        $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 1, 'Charge' => 0, 'Rate' => 0, 'SubjectID' => 98, 'ClassType' => 'trial']);

        $row = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $old->ID);
        $this->assertNotNull($row);
        $this->assertFalse($row['renewed_by_newer_course']);
        $this->assertSame('renew_needed', $row['payment_status']);
    }

    // Codex P2 on #4887: with A < B < C, A's overlap is checked against B (its successor), not the newest C.
    public function test_overlap_uses_the_nearest_later_course(): void
    {
        $token = $this->createDirectorToken([1], 'overlap-abc@example.com');
        $student = Student::create([
            'name' => 'T495c', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $a = $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 0, 'Charge' => 8000, 'SubjectID' => 97, 'EndDate' => '2026-08-08']);
        $b = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 8, 'Charge' => 8000, 'SubjectID' => 97, 'StartDate' => '2026-08-05']);
        $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 8, 'Charge' => 8000, 'SubjectID' => 97, 'StartDate' => '2026-09-01']);

        $row = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $a->ID);
        $this->assertNotNull($row);
        $this->assertSame($b->ID, $row['newer_course_id']);
        $this->assertTrue($row['newer_course_overlap']);
    }

    // in-app #495: a newer same-subject contract that is itself listed still supersedes the old one.
    public function test_listed_newer_course_supersedes_old_one_and_clears_renew_needed(): void
    {
        $token = $this->createDirectorToken([1], 'superseded@example.com');
        $student = Student::create([
            'name' => 'T495', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $old = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 0, 'Charge' => 8000, 'SubjectID' => 95]);
        $new = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 2, 'Charge' => 8000, 'SubjectID' => 95]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $rows = collect($res->json());
        $oldRow = $rows->firstWhere('id', $old->ID);
        $newRow = $rows->firstWhere('id', $new->ID);
        $this->assertNotNull($oldRow);
        $this->assertNotNull($newRow);
        $this->assertTrue($oldRow['has_newer_course']);
        $this->assertSame($new->ID, $oldRow['newer_course_id']);
        $this->assertTrue($oldRow['renewed_by_newer_course']);
        // The status stays renew_needed: it keeps the old contract's 結案 action (Codex P2 on #4887).
        $this->assertSame('renew_needed', $oldRow['payment_status']);
        $this->assertFalse($newRow['has_newer_course']);
    }

    /** #5494: existing metadata update for an already settled package, not a member close operation. */
    public function test_inapp573_retiring_an_already_settled_package_removes_only_its_renewal_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1], 'inapp573-exact-shape@example.com');
        $student = Student::create([
            'name' => 'Synthetic settled package', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => 'Synthetic exhausted package',
            'total_sessions' => 3, 'remaining_sessions' => 0, 'used_sessions' => 3,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'billing_mode' => CoursePackage::BILLING_MODE_SESSION,
            'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $member = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'Charge' => 0, 'SessionCount' => 3, 'RemainingSessions' => 0, 'UsedSessions' => 3,
            'PackageID' => $package->id, 'PackageTotalSessions' => 3, 'PackageName' => $package->name,
            'Stop' => 1, 'closed_reason' => 'settled',
        ]);
        foreach (['2026-11-15', '2026-11-16', '2026-11-17', '2026-11-24', '2026-11-25', '2026-11-26'] as $index => $date) {
            \Illuminate\Support\Facades\DB::table('ClassSession')->insert([
                'StudentClassID' => $member->ID, 'SessionDate' => $date,
                'StartTime' => '16:00', 'EndTime' => '18:00',
                'Status' => $index < 3 ? 'attended' : 'cancelled',
            ]);
        }
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $member->ID, 'IssueDate' => '2026-11-15',
            'TotalAmount' => 3000, 'PaidAmount' => 3000, 'Status' => 'paid',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 3000, 'PaidAt' => '2026-11-16', 'Method' => 'cash',
        ]);
        PaymentReport::create([
            'StudentClassID' => $member->ID, 'StudentID' => $student->id, 'InvoiceID' => $invoice->id,
            'reported_amount' => 3000, 'payment_date' => '2026-11-16', 'status' => 'confirmed',
            'payment_id' => $payment->id, 'reported_by_name' => $student->name, 'payment_method' => 'cash',
            'report_token_hash' => hash('sha256', 'synthetic-settled-package'),
            'token_expires_at' => now()->addDay(),
        ]);

        $controlStudent = Student::create([
            'name' => 'Synthetic other open course', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $control = $this->createCountModeClass($controlStudent->id, ['Paid' => 0, 'Charge' => 2000]);
        $this->withToken($token);
        $alertsPath = '/api/v1/alerts/tuition?branch_id=1';
        $before = collect($this->getJson($alertsPath)->assertOk()->json());
        $target = $before->firstWhere('package_id', $package->id);
        $this->assertNotNull($target);
        $this->assertSame('renew_needed', $target['payment_status']);
        $this->assertSame(0, $target['outstanding']);
        $this->assertNotNull($before->firstWhere('id', $control->ID));
        $tables = ['StudentClass', 'ClassSession', 'Invoice', 'Payment', 'payment_reports', 'package_session_ledger'];
        $snapshot = fn () => collect($tables)->mapWithKeys(
            fn (string $table) => [$table => \Illuminate\Support\Facades\DB::table($table)->get()->toArray()]
        )->all();
        $originalRecords = $snapshot();
        $packageWithoutStop = fn () => array_diff_key($package->fresh()->getRawOriginal(), ['stop' => true, 'updated_at' => true]);
        $originalPackageOtherFields = $packageWithoutStop();

        $path = "/api/v1/course-packages/{$package->id}";
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->putJson($path, ['stop' => true])->assertOk()
                ->assertJsonPath('package.stop', true)
                ->assertJsonPath('cancelled_sessions', [])
                ->assertJsonPath('extended_count', 0);
            $this->assertTrue($package->fresh()->stop);
            $this->assertSame($originalPackageOtherFields, $packageWithoutStop());
            $this->assertEquals($originalRecords, $snapshot());
        }

        $after = collect($this->getJson($alertsPath)->assertOk()->json());
        $this->assertNull($after->firstWhere('package_id', $package->id), 'A retired package must stay absent after reloading alerts.');
        $this->assertSame($before->firstWhere('id', $control->ID), $after->firstWhere('id', $control->ID));
    }

    /** #5494: RFC_SHARED_PACKAGE_COLLECTION acceptance40 forbids closing a pool through its member ID. */
    #[\PHPUnit\Framework\Attributes\DataProvider('inapp5494PackageCloseRequests')]
    public function test_inapp5494_package_close_must_not_claim_success_while_pool_reappears(array $request, string $mode, int $remaining): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1], 'inapp5494-synthetic@example.com');
        $student = Student::create([
            'name' => 'Synthetic pool close', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => 'Synthetic exhausted pool',
            'total_sessions' => 8, 'remaining_sessions' => $remaining, 'used_sessions' => 8 - $remaining,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $member = ['Paid' => 1, 'Charge' => 0, 'SessionCount' => 8, 'RemainingSessions' => $remaining, 'ScheduleMode' => $mode,
            'PackageID' => $package->id, 'PackageTotalSessions' => 8, 'PackageName' => $package->name];
        $a = $this->createCountModeClass($student->id, $member);
        $b = $this->createCountModeClass($student->id, $member + ['SubjectID' => 2]);
        foreach (['2026-11-17' => 'attended', '2026-11-19' => 'scheduled'] as $date => $status) {
            \Illuminate\Support\Facades\DB::table('ClassSession')->insert([
                'StudentClassID' => $a->ID, 'SessionDate' => $date, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => $status,
            ]);
        }
        $snapshot = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $package->fresh()->getAttributes(),
            \Illuminate\Support\Facades\DB::table('ClassSession')->orderBy('id')->get()->toArray()];
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $before = collect($this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=1')
            ->assertOk()->json())->firstWhere('package_id', $package->id);
        $this->assertNotNull($before);
        $this->assertSame((int) $a->ID, (int) $before['id']);
        $response = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$before['id']}/pause", $request);
        $after = collect($this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=1')
            ->assertOk()->json())->firstWhere('package_id', $package->id);
        $diagnostic = json_encode([
            'http' => $response->status(), 'message' => $response->json('message'),
            'before' => array_intersect_key($before, array_flip(['id','package_id','payment_status','remaining_sessions'])),
            'after' => $after === null ? null : array_intersect_key($after, array_flip(['id','package_id','payment_status','remaining_sessions'])),
            'package_stop' => (bool) $package->fresh()->stop,
            'member_stop' => [(int) $a->fresh()->Stop, (int) $b->fresh()->Stop],
            'invoices' => Invoice::count(), 'payments' => Payment::count(),
        ], JSON_UNESCAPED_UNICODE);
        // Existing accepted invariant, not a newly defined whole-package cancellation command.
        $this->assertSame(422, $response->status(), (string) $diagnostic);
        $response->assertJsonPath('error_code', 'package_close_requires_review');
        $this->assertEquals($snapshot, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $package->fresh()->getAttributes(),
            \Illuminate\Support\Facades\DB::table('ClassSession')->orderBy('id')->get()->toArray()]);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertNotNull($after);
        $this->assertSame((int) $a->ID, (int) $after['id']);
        $this->assertSame(0, (int) $a->fresh()->Stop);
        $this->assertSame(0, (int) $b->fresh()->Stop);
        $this->assertFalse((bool) $package->fresh()->stop);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: int}> */
    public static function inapp5494PackageCloseRequests(): array
    {
        return [
            'explicit count settled' => [['action' => 'pause', 'reason' => 'settled'], 'count', 0],
            'explicit count completed' => [['action' => 'pause', 'reason' => 'completed'], 'count', 0],
            'inferred exhausted count completion' => [['action' => 'pause'], 'count', 0],
            'explicit monthly close' => [['action' => 'pause', 'reason' => 'settled'], 'date', 0],
            'inferred monthly completion' => [['action' => 'pause'], 'date', 0],
            'forfeit does not grant pool closure' => [['action' => 'pause', 'reason' => 'settled', 'forfeit_remaining' => true], 'count', 1],
        ];
    }

    public function test_settle_closes_course_and_removes_from_alerts(): void
    {
        $token = $this->createDirectorToken([1], 'settle@example.com');
        $student = Student::create([
            'name' => '結案測試', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1, 'RemainingSessions' => 0, 'Charge' => 8000,
        ]);

        $pauseRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'pause',
            'reason' => 'settled',
        ]);

        $pauseRes->assertOk();
        $this->assertStringContainsString('已結案', $pauseRes->json('message'));

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled', $course->closed_reason);
        $this->assertNotNull($course->EndDate);

        $alertRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $alertRes->assertOk();
        $ids = collect($alertRes->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids);
    }

    public function test_outstanding_never_negative(): void
    {
        $token = $this->createDirectorToken([1], 'ps-negative@example.com');
        $student = Student::create([
            'name' => 'Charge 為空',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 3, 'Charge' => 5000,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(0, $row['outstanding']);
    }

    public function test_voided_invoice_does_not_increase_outstanding(): void
    {
        $token = $this->createDirectorToken([1], 'void-invoice-alert@example.com');
        $student = Student::create([
            'name' => '作廢帳單學生',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 0, 'RemainingSessions' => 1, 'Charge' => 5000,
        ]);

        Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-15',
            'TotalAmount' => 5000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-04',
        ]);
        Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-05-01',
            'DueDate' => '2026-05-15',
            'TotalAmount' => 5000,
            'PaidAmount' => 0,
            'Status' => 'void',
            'billing_period' => '2026-05',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame(5000, (int) $row['outstanding'], 'void invoice should not count as receivable');
    }

    public function test_count_mode_amount_is_the_stored_charge_even_when_rate_times_count_differs(): void
    {
        $token = $this->createDirectorToken([1], 'director-stale-charge@example.com');
        $student = Student::create([
            'name' => '收帳金額測試', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $course = $this->createCountModeClass($student->id, [
            'Rate' => 1650,
            'SessionCount' => 8,
            'Charge' => 24750,
            'Paid' => 0,
            'RemainingSessions' => 8,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        // Founder Q3 (2026-10-11): StudentClass.Charge everywhere; the Rate x count mismatch goes to a director-confirm list.
        $this->assertSame(24750, (int) $row['charge']);
        $this->assertSame(24750, (int) $row['outstanding']);
    }

    public function test_count_mode_empty_charge_keeps_the_estimate_in_the_queue_but_the_slip_refuses_to_price_it(): void
    {
        $token = $this->createDirectorToken([1], 'director-empty-charge@example.com');
        $student = Student::create(['name' => '空學費', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = $this->createCountModeClass($student->id, ['Rate' => 1000, 'SessionCount' => 8, 'Charge' => 0, 'Paid' => 0, 'RemainingSessions' => 8]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $row = collect($this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row, 'Charge 0 + Rate is "not filled in", not free: it must not vanish from the queue');
        $this->assertSame(8000, (int) $row['charge']);
        // A parent-facing slip never carries an amount invented from Rate (Founder 2026-10-14).
        $this->withHeaders($headers)->getJson("/api/v1/alerts/tuition-slip/{$course->ID}")->assertStatus(422);
    }

    public function test_package_member_slip_keeps_package_pricing_when_its_stored_charge_differs(): void
    {
        $token = $this->createDirectorToken([1], 'director-package-slip@example.com');
        $student = Student::create(['name' => '方案繳費單', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '方案繳費單測試', 'total_sessions' => 8, 'remaining_sessions' => 8, 'used_sessions' => 0,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        // convertToPackage() keeps the source course's historical Charge: it is not the package's price.
        $anchor = $this->createCountModeClass($student->id, [
            'Rate' => 1000, 'SessionCount' => 8, 'Charge' => 5000, 'Paid' => 0, 'RemainingSessions' => 8,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 8, 'PackageName' => $pkg->name,
        ]);

        $slip = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/alerts/tuition-slip/{$anchor->ID}")->assertOk()->assertJsonPath('charge', 8000);
        // 單價 on the slip follows what the amount is built from: this course's own rate (1000 a lesson).
        $this->assertEquals(1000, $slip->json('unit_price'));
        $this->assertSame('session', $slip->json('unit_price_unit'));

        // The unit rides along: a course priced by the hour says so.
        $hourly = $this->createCountModeClass($student->id, ['Rate' => 900, 'rate_unit' => 'hour', 'TotalHours' => 8, 'SessionCount' => 4, 'Charge' => 7200, 'Paid' => 0, 'RemainingSessions' => 4]);
        $own = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->getJson("/api/v1/alerts/tuition-slip/{$hourly->ID}")->assertOk();
        $this->assertEquals(900, $own->json('unit_price'));
        $this->assertSame('hour', $own->json('unit_price_unit'));
    }

    // ── #4123 / in-app #407 (#4114): 最近繳費 and 已收 come from valid Payment rows ──────────────

    private const PAID_STATUSES = ['paid', 'renew_needed', 'monthly_due_soon'];

    private function tuitionRow(string $token, int $classId): array
    {
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1');
        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $classId);
        $this->assertNotNull($row, 'contract should be on the tuition list');

        return $row;
    }

    private function plainStudent(string $name): Student
    {
        return Student::create(['name' => $name, 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    public function test_last_paid_at_ignores_void_and_negative_payments(): void
    {
        $token = $this->createDirectorToken([1], 'lp-void@example.com');
        $student = $this->plainStudent('撤銷款不算');
        $course = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 2]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000, 'PaidAmount' => 5000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-04-05 10:00:00', 'Method' => 'cash']);
        // later reversal rows: a void-method row and a plain negative row must not become the 最近繳費 date
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -300, 'PaidAt' => '2026-04-20', 'Method' => 'void']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -200, 'PaidAt' => '2026-04-25', 'Method' => 'cash']);

        $this->assertSame('2026-04-05', $this->tuitionRow($token, (int) $course->ID)['last_paid_at']);
    }

    public function test_last_paid_at_prefers_valid_payment_over_stale_paydate(): void
    {
        $token = $this->createDirectorToken([1], 'lp-stale@example.com');
        $student = $this->plainStudent('舊繳費日');
        $course = $this->createCountModeClass($student->id, ['Paid' => 1, 'PayDate' => '2025-01-15', 'RemainingSessions' => 2]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000, 'PaidAmount' => 5000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-04-05 10:00:00', 'Method' => 'cash']);

        $this->assertSame('2026-04-05', $this->tuitionRow($token, (int) $course->ID)['last_paid_at']);
    }

    public function test_stale_paydate_without_payment_is_not_a_paid_status(): void
    {
        $token = $this->createDirectorToken([1], 'lp-stale-nopay@example.com');
        $student = $this->plainStudent('只有舊日期');
        $course = $this->createCountModeClass($student->id, ['Paid' => 0, 'PayDate' => '2025-01-15', 'RemainingSessions' => 5]);

        $row = $this->tuitionRow($token, (int) $course->ID);
        $this->assertNotContains($row['payment_status'], self::PAID_STATUSES);
    }

    public function test_no_invoice_and_no_payment_is_not_a_paid_status(): void
    {
        $token = $this->createDirectorToken([1], 'lp-none@example.com');
        $student = $this->plainStudent('沒帳單沒付款');
        $course = $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 5]);

        $row = $this->tuitionRow($token, (int) $course->ID);
        $this->assertNotContains($row['payment_status'], self::PAID_STATUSES);
        $this->assertNull($row['last_paid_at']);
    }

    public function test_pending_report_without_invoice_is_not_a_paid_status(): void
    {
        $token = $this->createDirectorToken([1], 'lp-pending@example.com');
        $student = $this->plainStudent('回報待核');
        $course = $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 5]);
        PaymentReport::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'reported_by_name' => 'x',
            'payment_date' => Carbon::today(), 'payment_method' => 'cash', 'reported_amount' => 5000, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'lp-pending'), 'token_expires_at' => Carbon::now()->addDay()]);

        $row = $this->tuitionRow($token, (int) $course->ID);
        $this->assertSame('pending_report', $row['payment_status']);
        $this->assertNull($row['last_paid_at']);
    }

    public function test_voided_report_is_not_a_paid_status_and_its_receipt_date_is_gone(): void
    {
        $token = $this->createDirectorToken([1], 'lp-voided@example.com');
        $student = $this->plainStudent('回報已撤銷');
        // state after PaymentReportController::void: Paid=0, PayDate=null, invoice back to unpaid, a void reversal row
        $course = $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 5]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $report = PaymentReport::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'x', 'payment_date' => '2026-04-05', 'payment_method' => 'cash', 'reported_amount' => 5000,
            'status' => 'voided', 'report_token_hash' => hash('sha256', 'lp-voided'), 'token_expires_at' => Carbon::now()->addDay()]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-04-05', 'Method' => 'cash', 'payment_report_id' => $report->id]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -5000, 'PaidAt' => '2026-04-20', 'Method' => 'void', 'payment_report_id' => $report->id]);

        $row = $this->tuitionRow($token, (int) $course->ID);
        $this->assertNotContains($row['payment_status'], self::PAID_STATUSES);
        $this->assertNull($row['last_paid_at'], 'a reversed receipt is not a 最近繳費 date');
    }

    public function test_record_payment_then_exception_void_leaves_no_last_paid_at(): void
    {
        $token = $this->createDirectorToken([1], 'lp-exvoid@example.com');
        $student = $this->plainStudent('收款後例外作廢');
        $course = $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 2]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson("/api/v1/invoices/{$invoice->id}/payments", ['Amount' => 5000, 'PaidAt' => '2026-04-05', 'Method' => 'cash'])->assertSuccessful();
        $this->assertSame('2026-04-05', $this->tuitionRow($token, (int) $course->ID)['last_paid_at']);
        $this->withHeaders($headers)->postJson("/api/v1/invoices/{$invoice->id}/exception-void", ['reason' => 'receipt entered on the wrong bill'])->assertOk();

        $this->assertNull($this->tuitionRow($token, (int) $course->ID)['last_paid_at'], 'the stale PayDate must not come back once the only receipt is voided');
    }

    public function test_legacy_backfilled_receipt_voided_then_repaid_shows_the_repayment_date(): void
    {
        // #4149 review: the backfill links its receipt only through PaymentReport.payment_id; the report void's reversal
        // carries the report id. The repayment after the void is the 最近繳費 date, not the voided legacy receipt.
        $token = $this->createDirectorToken([1], 'lp-legacy-void-repay@example.com');
        $student = $this->plainStudent('舊收據撤銷後重繳');
        $course = $this->createCountModeClass($student->id, ['Paid' => 1, 'PayDate' => '2026-03-01', 'RemainingSessions' => 2]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        try {
            Carbon::setTestNow('2026-04-20 10:00:00');
            $this->artisan('payments:backfill-legacy')->assertExitCode(0);
            $report = PaymentReport::query()->where('StudentClassID', $course->ID)->firstOrFail();
            $this->assertNotNull($report->payment_id);
            $this->assertNull(Payment::query()->findOrFail($report->payment_id)->payment_report_id, 'backfill shape: no payment_report_id');
            $this->assertSame('2026-03-01', $this->tuitionRow($token, (int) $course->ID)['last_paid_at']);

            $this->withHeaders($headers)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => 'wrong legacy receipt'])->assertOk();
            $this->assertNull($this->tuitionRow($token, (int) $course->ID)['last_paid_at']);

            Carbon::setTestNow('2026-05-06 10:00:00');
            $this->withHeaders($headers)->postJson("/api/v1/invoices/{$report->InvoiceID}/payments", ['Amount' => 5000, 'PaidAt' => '2026-05-05', 'Method' => 'cash'])->assertSuccessful();

            $this->assertSame('2026-05-05', $this->tuitionRow($token, (int) $course->ID)['last_paid_at']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_flag_only_contract_without_payment_history_keeps_paydate_fallback(): void
    {
        $token = $this->createDirectorToken([1], 'lp-flag-only@example.com');
        $student = $this->plainStudent('只有旗標');
        $course = $this->createCountModeClass($student->id, ['Paid' => 1, 'PayDate' => '2025-03-01', 'RemainingSessions' => 2]);

        $this->assertSame('2025-03-01', $this->tuitionRow($token, (int) $course->ID)['last_paid_at']);
    }

    public function test_partial_payment_is_partial_not_paid(): void
    {
        $token = $this->createDirectorToken([1], 'lp-partial@example.com');
        $student = $this->plainStudent('部分繳費');
        $course = $this->createCountModeClass($student->id, ['Paid' => 0, 'RemainingSessions' => 5]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000, 'PaidAmount' => 2000, 'Status' => 'partial']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 2000, 'PaidAt' => '2026-04-05', 'Method' => 'cash']);

        $row = $this->tuitionRow($token, (int) $course->ID);
        $this->assertSame('partial', $row['payment_status']);
        $this->assertGreaterThan(0, (int) $row['payable_outstanding']);
        $this->assertSame('2026-04-05', $row['last_paid_at']);
    }

    private function createDirectorToken(array $campusIds, string $loginName = 'director-tuition@example.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createCountModeClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 5000,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createMonthlyClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 5000,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 1,
            'monthly_sessions' => null,
        ], $overrides));
    }

    /**
     * #4114 x #4123: a receipt cancelled by a reversal (negative row or Method='void' row) is not a payment record, the same
     * net ledger last_paid_at uses. When nothing is owed (bill zero-rated after the reversal) the paid-flag row reads 需核對
     * with no 最近繳費 date; while the bill still owes it is plain unpaid, never flag-only. A partial reversal leaves a
     * receipt standing: real 已收 with a date.
     */
    public function test_reversed_receipt_is_not_payment_evidence(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-reversed@example.com');
        $student = $this->plainStudent('S4114R');
        $make = function (int $subject, int $total, array $rows) use ($student) {
            $course = $this->createCountModeClass($student->id, ['Paid' => 1, 'RemainingSessions' => 1, 'Charge' => 5000, 'SubjectID' => $subject]);
            $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01',
                'TotalAmount' => $total, 'PaidAmount' => $total, 'Status' => 'paid']);
            foreach ($rows as [$amount, $method, $at]) {
                Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'PaidAt' => $at, 'Method' => $method]);
            }

            return (int) $course->ID;
        };
        $negative = [[5000, 'cash', '2026-04-05'], [-5000, 'cash', '2026-04-06']];
        $voidRow = [[5000, 'cash', '2026-04-05'], [5000, 'void', '2026-04-06']];

        foreach ([$negative, $voidRow] as $i => $ledger) {
            $settled = $this->tuitionRow($token, $make(1 + $i, 0, $ledger));
            $this->assertSame(['renew_needed', true, null], [$settled['payment_status'], $settled['legacy_paid_flag_only'], $settled['last_paid_at']],
                'a fully reversed receipt is not a payment record');
            $owed = $this->tuitionRow($token, $make(3 + $i, 5000, $ledger));
            $this->assertSame(['unpaid', false, null], [$owed['payment_status'], $owed['legacy_paid_flag_only'], $owed['last_paid_at']]);
        }
        $partial = $this->tuitionRow($token, $make(5, 3000, [[5000, 'cash', '2026-04-05'], [-2000, 'cash', '2026-04-06']]));
        $this->assertSame(['renew_needed', false, '2026-04-05'], [$partial['payment_status'], $partial['legacy_paid_flag_only'], $partial['last_paid_at']]);
    }

    /** #4174 review: a paid status with no legacy flag behind it (package `paid`=false, bill stored as paid, no Payment) is not 需核對. */
    public function test_legacy_paid_flag_only_requires_an_actual_legacy_flag(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-noflag@example.com');
        $student = $this->plainStudent('S4174F');
        $pkg = CoursePackage::create(['student_id' => $student->id, 'campus_id' => 1, 'name' => 'NoFlag', 'total_sessions' => 8,
            'remaining_sessions' => 1, 'used_sessions' => 7, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true]);
        $anchor = $this->createCountModeClass($student->id, ['RemainingSessions' => 1, 'SessionCount' => 8, 'PackageID' => $pkg->id]);
        Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $anchor->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid']);

        $row = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('package_id', $pkg->id);

        $this->assertSame('renew_needed', $row['payment_status'], 'paid-like status is the shape under test');
        $this->assertFalse($row['legacy_paid_flag_only'], 'no Paid / package paid flag: nothing legacy to verify');
    }

    /**
     * #4174 review: a course of another student on another campus linked to the same PackageID by bad data never counts as
     * this package's payment record: its receipt must not clear the visible package's 需核對.
     */
    public function test_package_payment_evidence_stays_inside_owner_and_campus(): void
    {
        $token = $this->createDirectorToken([1], 'legacy-xcampus@example.com');
        $owner = $this->plainStudent('S4174X');
        $foreign = Student::create(['name' => 'S4174Y', 'CampusID' => 2, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $pkg = CoursePackage::create(['student_id' => $owner->id, 'campus_id' => 1, 'name' => 'XCampus', 'total_sessions' => 8,
            'remaining_sessions' => 1, 'used_sessions' => 7, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => true, 'stop' => false, 'enabled' => true]);
        $this->createCountModeClass($owner->id, ['RemainingSessions' => 1, 'SessionCount' => 8, 'PackageID' => $pkg->id]);
        $stray = $this->createCountModeClass($foreign->id, ['RemainingSessions' => 1, 'SessionCount' => 8, 'PackageID' => $pkg->id, 'SubjectID' => 6]);
        Payment::create(['InvoiceID' => Invoice::create(['StudentID' => $foreign->id, 'StudentClassID' => $stray->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 8000, 'PaidAmount' => 8000, 'Status' => 'paid'])->id, 'Amount' => 8000, 'PaidAt' => '2026-04-05', 'Method' => 'cash']);

        $row = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('package_id', $pkg->id);

        $this->assertSame('renew_needed', $row['payment_status']);
        $this->assertTrue($row['legacy_paid_flag_only'], 'another campus\'s receipt is not this package\'s payment record');

        $resolver = app(\App\Services\BillingPayableResolver::class);
        $this->assertSame([], $resolver->validPaymentEvidence([], [$pkg->id], [1])['packages'], 'campus scope excludes the stray member');
        $this->assertSame([], $resolver->validPaymentEvidence([], [$pkg->id])['packages'], 'owner rule excludes it even unscoped');
    }
}
