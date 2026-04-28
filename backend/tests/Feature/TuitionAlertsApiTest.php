<?php

namespace Tests\Feature;

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

class TuitionAlertsApiTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertNull($row['latest_payment_report_id']);
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
            'RoomID' => 'R1',
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
            'RoomID' => 'R1',
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
}
