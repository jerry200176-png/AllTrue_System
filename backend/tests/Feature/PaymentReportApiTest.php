<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\PaymentReportTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── generate-link ──────────────────────────────────────────────

    public function test_director_can_generate_report_link(): void
    {
        $this->markTestSkipped('Deprecated: pay-report/generate-link public endpoints removed (reconciliation is now director-only via /api/v1/payment-reports/director-record)');

        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8800]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payment-reports/generate-link', [
            'student_class_id' => $sc->ID,
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['link', 'token', 'expires_at', 'student_name', 'charge']);
        $this->assertStringContains('/pay-report?token=', $res->json('link'));
        $this->assertEquals(8800, $res->json('charge'));
    }

    // ── formData (GET pay-report) ──────────────────────────────────

    public function test_parent_can_fetch_form_data_with_valid_token(): void
    {
        $this->markTestSkipped('Deprecated: pay-report public endpoint removed');

        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 9600, 'SessionCount' => 8]);

        $tokenService = new PaymentReportTokenService();
        $result = $tokenService->generate($sc->ID, 1);

        $res = $this->getJson('/api/v1/pay-report?token=' . urlencode($result['token']));

        $res->assertOk();
        $res->assertJsonFragment(['student_name' => $student->name]);
        $this->assertEquals(9600, $res->json('charge'));
        $this->assertEquals(8, $res->json('session_count'));
    }

    public function test_expired_token_returns_403(): void
    {
        $this->markTestSkipped('Deprecated: pay-report public endpoint removed');

        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $tokenService = new PaymentReportTokenService();
        $result = $tokenService->generate($sc->ID, 1, 0); // 0 hour TTL = already expired

        Carbon::setTestNow(Carbon::now()->addHour());

        $res = $this->getJson('/api/v1/pay-report?token=' . urlencode($result['token']));
        $res->assertStatus(403);
    }

    // ── submit (POST pay-report) ──────────────────────────────────

    public function test_parent_can_submit_transfer_report(): void
    {
        $this->markTestSkipped('Deprecated: pay-report submit endpoint removed');

        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8800]);

        $tokenService = new PaymentReportTokenService();
        $result = $tokenService->generate($sc->ID, 1);

        $res = $this->postJson('/api/v1/pay-report', [
            'token' => $result['token'],
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '45688',
        ]);

        $res->assertStatus(201);
        $res->assertJsonFragment(['message' => '繳費回報已送出，請等待會計確認']);

        $report = PaymentReport::first();
        $this->assertNotNull($report);
        $this->assertEquals('pending', $report->status);
        $this->assertEquals('transfer', $report->payment_method);
        $this->assertEquals('45688', $report->account_last5);
        $this->assertEquals(8800, (float) $report->reported_amount);
    }

    public function test_parent_can_submit_cash_report_without_last5(): void
    {
        $this->markTestSkipped('Deprecated: pay-report submit endpoint removed');

        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 5000]);

        $tokenService = new PaymentReportTokenService();
        $result = $tokenService->generate($sc->ID, 1);

        $res = $this->postJson('/api/v1/pay-report', [
            'token' => $result['token'],
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
        ]);

        $res->assertStatus(201);
        $report = PaymentReport::first();
        $this->assertEquals('cash', $report->payment_method);
        $this->assertNull($report->account_last5);
    }

    public function test_transfer_without_last5_returns_422(): void
    {
        $this->markTestSkipped('Deprecated: pay-report submit endpoint removed');

        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $tokenService = new PaymentReportTokenService();
        $result = $tokenService->generate($sc->ID, 1);

        $res = $this->postJson('/api/v1/pay-report', [
            'token' => $result['token'],
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '',
        ]);

        $res->assertStatus(422);
    }

    public function test_duplicate_submit_returns_409(): void
    {
        $this->markTestSkipped('Deprecated: pay-report submit endpoint removed');

        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $tokenService = new PaymentReportTokenService();
        $result = $tokenService->generate($sc->ID, 1);

        $this->postJson('/api/v1/pay-report', [
            'token' => $result['token'],
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
        ])->assertStatus(201);

        $res = $this->postJson('/api/v1/pay-report', [
            'token' => $result['token'],
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
        ]);

        $res->assertStatus(409);
    }

    // ── index (director list reports) ──────────────────────────────

    public function test_director_can_list_payment_reports(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '12345',
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'test-token-1'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payment-reports?branch_id=1');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals('pending', $res->json('data.0.status'));
    }

    public function test_director_record_can_target_specific_invoice_period(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');

        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, [
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'Charge' => 8800,
            'Paid' => 0,
        ]);

        $april = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-04',
        ]);
        $may = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-05-01',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'billing_period' => '2026-05',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $sc->ID,
            'invoice_id' => $may->id,
            'payment_date' => '2026-04-28',
            'payment_method' => 'cash',
            'amount' => 8800,
        ]);

        $res->assertOk()
            ->assertJsonPath('invoice_id', (int) $may->id);

        $april->refresh();
        $may->refresh();
        $this->assertSame('unpaid', (string) $april->Status);
        $this->assertSame(0, (int) $april->PaidAmount);
        $this->assertSame('paid', (string) $may->Status);
        $this->assertSame(8800, (int) $may->PaidAmount);
    }

    public function test_student_class_invoices_include_payment_date_separate_from_due_date(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');

        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, [
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'Charge' => 8800,
            'Paid' => 1,
            'PayDate' => '2026-04-10',
        ]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-05-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 8800,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 8800,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
            'Note' => '主任核帳登記',
        ]);
        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-04-10',
            'payment_method' => 'cash',
            'reported_amount' => 8800,
            'status' => 'confirmed',
            'payment_id' => $payment->id,
            'report_token_hash' => hash('sha256', 'invoice-detail-report'),
            'token_expires_at' => Carbon::now(),
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => -8800,
            'PaidAt' => '2026-04-12',
            'Method' => 'void',
            'Note' => '沖銷紀錄不算有效付款',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$sc->ID}/invoices");

        $res->assertOk()
            ->assertJsonPath('invoices.0.id', (int) $invoice->id)
            ->assertJsonPath('invoices.0.billing_period', '2026-04')
            ->assertJsonPath('invoices.0.due_date', '2026-05-15')
            ->assertJsonPath('invoices.0.paid_at', '2026-04-10')
            ->assertJsonPath('invoices.0.payment_count', 1)
            ->assertJsonPath('invoices.0.payments.0.amount', 8800)
            ->assertJsonPath('invoices.0.payments.0.receipt_no', 'RCPT-202604-' . str_pad((string) $report->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('invoices.0.payments.0.is_void', false)
            ->assertJsonPath('invoices.0.payments.1.amount', -8800)
            ->assertJsonPath('invoices.0.payments.1.is_void', true);
    }

    public function test_director_record_rejects_duplicate_payment_when_course_already_paid(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');

        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, [
            'ScheduleMode' => 'count',
            'Charge' => 8800,
            'Paid' => 1,
            'PayDate' => '2026-04-10',
        ]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800,
            'PaidAmount' => 8800,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 8800,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
            'Note' => '已核帳',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $sc->ID,
            'payment_date' => '2026-04-28',
            'payment_method' => 'cash',
            'amount' => 8800,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'course_already_paid');

        $this->assertSame(1, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame(1, Invoice::where('StudentClassID', $sc->ID)->count());
    }

    public function test_confirm_rejects_duplicate_payment_when_course_already_paid(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');

        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, [
            'ScheduleMode' => 'count',
            'Charge' => 8800,
            'Paid' => 1,
            'PayDate' => '2026-04-10',
        ]);

        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800,
            'PaidAmount' => 8800,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 8800,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
            'Note' => '已核帳',
        ]);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-04-28',
            'payment_method' => 'cash',
            'reported_amount' => 8800,
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'test-confirm-duplicate'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/confirm");

        $res->assertStatus(422)
            ->assertJsonPath('code', 'course_already_paid');

        $report->refresh();
        $this->assertSame('pending', (string) $report->status);
        $this->assertNull($report->payment_id);
        $this->assertSame(1, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame(1, Invoice::where('StudentClassID', $sc->ID)->count());
    }

    // ── confirm ────────────────────────────────────────────────────

    public function test_director_can_confirm_report(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Paid' => 0, 'Charge' => 8800]);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '45688',
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'test-confirm-1'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/confirm");

        $res->assertOk();
        $res->assertJsonFragment(['message' => '已確認入帳']);

        $report->refresh();
        $this->assertEquals('confirmed', $report->status);
        $this->assertNotNull($report->confirmed_at);
        $this->assertNotNull($report->payment_id);

        $payment = Payment::find($report->payment_id);
        $this->assertNotNull($payment);
        $this->assertEquals(8800, $payment->Amount);
        $this->assertEquals('transfer', $payment->Method);

        $sc->refresh();
        $this->assertEquals(1, $sc->Paid);
    }

    public function test_cannot_confirm_already_confirmed(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now(),
            'report_token_hash' => hash('sha256', 'test-dup-confirm'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/confirm");

        $res->assertStatus(422);
    }

    // ── reject ─────────────────────────────────────────────────────

    public function test_director_can_reject_report(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '99999',
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'test-reject-1'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/reject", [
            'rejection_note' => '查無入帳記錄',
        ]);

        $res->assertOk();
        $report->refresh();
        $this->assertEquals('rejected', $report->status);
        $this->assertEquals('查無入帳記錄', $report->rejection_note);
    }

    // ── receipt ─────────────────────────────────────────────────────

    public function test_receipt_requires_confirmed_status(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'test-receipt-pend'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payment-reports/{$report->id}/receipt");

        $res->assertStatus(422);
    }

    public function test_receipt_returns_data_for_confirmed(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8800]);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => 1,
            'report_token_hash' => hash('sha256', 'test-receipt-ok'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payment-reports/{$report->id}/receipt");

        $res->assertOk();
        $res->assertJsonStructure(['receipt_no', 'student_name', 'amount', 'payment_method', 'confirmed_at']);
        $this->assertEquals(8800, $res->json('amount'));
        $this->assertStringStartsWith('R-', $res->json('receipt_no'));
    }

    public function test_receipt_rejects_cross_campus_report(): void
    {
        $token = $this->createDirectorToken([2]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => 1,
            'report_token_hash' => hash('sha256', 'test-receipt-cross-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/payment-reports/{$report->id}/receipt");

        $res->assertStatus(403);
    }

    // ── void ────────────────────────────────────────────────────────

    public function test_director_can_void_confirmed_report(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Paid' => 1, 'Charge' => 8800, 'PayDate' => '2026-04-10']);

        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01', 'TotalAmount' => 8800, 'PaidAmount' => 8800, 'Status' => 'paid',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 8800,
            'PaidAt' => '2026-04-10', 'Method' => 'cash',
        ]);
        $report = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            'reported_amount' => 8800, 'status' => 'confirmed',
            'confirmed_by' => 1, 'confirmed_at' => Carbon::now(),
            'payment_id' => $payment->id,
            'report_token_hash' => hash('sha256', 'void-test-1'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/void", [
            'void_reason' => '核帳金額有誤',
        ]);

        $res->assertOk();
        $res->assertJsonFragment(['message' => '已撤銷收款']);

        $report->refresh();
        $this->assertSame('voided', $report->status);
        $this->assertSame('核帳金額有誤', $report->void_reason);
        $this->assertNotNull($report->voided_at);

        $invoice->refresh();
        $this->assertSame(0, (int) $invoice->PaidAmount);
        $this->assertSame('unpaid', $invoice->Status);

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid);
        $this->assertNull($sc->PayDate);

        $voidPayment = Payment::where('InvoiceID', $invoice->id)->where('Amount', '<', 0)->first();
        $this->assertNotNull($voidPayment);
        $this->assertSame(-8800, (int) $voidPayment->Amount);
        $this->assertSame('void', $voidPayment->Method);
    }

    public function test_void_rejects_pending_report(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            'reported_amount' => 5000, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'void-pending-test'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/void", [
            'void_reason' => '測試',
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['message' => '此回報尚未確認，無法撤銷']);
    }

    public function test_void_rejects_already_voided_report(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            'reported_amount' => 5000, 'status' => 'voided',
            'voided_at' => Carbon::now(), 'void_reason' => '已撤銷',
            'report_token_hash' => hash('sha256', 'void-voided-test'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/void", [
            'void_reason' => '再次撤銷',
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['message' => '此收款已撤銷過']);
    }

    public function test_void_requires_void_reason(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $report = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            'reported_amount' => 5000, 'status' => 'confirmed',
            'confirmed_at' => Carbon::now(),
            'report_token_hash' => hash('sha256', 'void-noreason-test'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/void", []);

        $res->assertStatus(422);
    }

    public function test_void_cross_campus_returns_403(): void
    {
        $token = $this->createDirectorToken([2]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Paid' => 1]);

        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01', 'TotalAmount' => 5000, 'PaidAmount' => 5000, 'Status' => 'paid',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-04-10', 'Method' => 'cash',
        ]);
        $report = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(), 'payment_method' => 'cash',
            'reported_amount' => 5000, 'status' => 'confirmed',
            'confirmed_at' => Carbon::now(), 'payment_id' => $payment->id,
            'report_token_hash' => hash('sha256', 'void-cross-campus'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->putJson("/api/v1/payment-reports/{$report->id}/void", [
            'void_reason' => '跨校測試',
        ]);

        $res->assertStatus(403);
    }

    // ── FR-001: student_class_id precise filter ─────────────────────

    public function test_student_class_id_filter_finds_confirmed_report_beyond_page_1(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $targetSc = $this->createCountModeClass($student->id, ['Charge' => 9999]);

        for ($i = 0; $i < 34; $i++) {
            $sc = $this->createCountModeClass($student->id);
            PaymentReport::create([
                'StudentID' => $student->id,
                'StudentClassID' => $sc->ID,
                'reported_by_name' => $student->name,
                'payment_date' => Carbon::today(),
                'payment_method' => 'cash',
                'reported_amount' => 1000,
                'status' => 'pending',
                'report_token_hash' => hash('sha256', 'bulk-' . $i . '-' . uniqid()),
                'token_expires_at' => Carbon::now()->addDay(),
            ]);
        }

        PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $targetSc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'transfer',
            'reported_amount' => 9999,
            'account_last5' => '11111',
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now(),
            'report_token_hash' => hash('sha256', 'target-report-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payment-reports?student_class_id=' . $targetSc->ID . '&status=confirmed');

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($targetSc->ID, $data[0]['student_class_id']);
        $this->assertEquals('confirmed', $data[0]['status']);
    }

    public function test_student_class_id_cross_campus_returns_403(): void
    {
        $token = $this->createDirectorToken([2]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => $student->name,
            'payment_date' => Carbon::today(),
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::now(),
            'report_token_hash' => hash('sha256', 'cross-sc-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payment-reports?student_class_id=' . $sc->ID . '&status=confirmed');

        $res->assertStatus(403);
    }

    public function test_student_class_id_filter_nonexistent_returns_404(): void
    {
        $token = $this->createDirectorToken([1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payment-reports?student_class_id=999999');

        $res->assertStatus(404);
    }

    // ── Accounting Center: payment ledger ───────────────────────────

    public function test_accounting_payments_lists_confirmed_payments_with_summary(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $cashClass = $this->createCountModeClass($student->id, ['Charge' => 5000]);
        $transferClass = $this->createCountModeClass($student->id, ['Charge' => 8800]);

        $this->createConfirmedReport($student, $cashClass, [
            'payment_date' => '2026-04-10',
            'payment_method' => 'cash',
            'reported_amount' => 5000,
        ]);
        $this->createConfirmedReport($student, $cashClass, [
            'payment_date' => '2026-04-12',
            'payment_method' => 'cash',
            'reported_amount' => 1000,
        ]);
        $this->createConfirmedReport($student, $transferClass, [
            'payment_date' => '2026-04-11',
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '12345',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/payments?branch_id=1&start=2026-04-01&end=2026-04-30');

        $res->assertOk()
            ->assertJsonPath('summary.total_count', 3)
            ->assertJsonPath('summary.unique_paid_course_count', 2)
            ->assertJsonPath('summary.duplicate_payment_course_count', 1)
            ->assertJsonPath('summary.duplicate_payment_extra_count', 1)
            ->assertJsonPath('summary.cash_total', 6000)
            ->assertJsonPath('summary.transfer_total', 8800)
            ->assertJsonPath('summary.grand_total', 14800);

        $this->assertCount(3, $res->json('data'));
        $this->assertSame(6000, collect($res->json('data'))->sum('cash_amount'));
        $this->assertSame(8800, collect($res->json('data'))->sum('transfer_amount'));
    }

    public function test_accounting_payments_marks_prepaid_when_payment_before_first_session(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-05-01',
            'StartTime' => '18:00',
            'EndTime' => '20:00',
            'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-20',
            'StartTime' => '18:00',
            'EndTime' => '20:00',
            'Status' => 'cancelled',
        ]);
        $this->createConfirmedReport($student, $sc, [
            'payment_date' => '2026-04-15',
            'payment_method' => 'cash',
            'reported_amount' => 5000,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/payments?branch_id=1&start=2026-04-01&end=2026-04-30');

        $res->assertOk()
            ->assertJsonPath('data.0.first_session_date', '2026-05-01')
            ->assertJsonPath('data.0.is_prepaid', true)
            ->assertJsonPath('summary.prepaid_count', 1);
    }

    public function test_accounting_payments_returns_first_session_for_monthly_course(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, [
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'monthly_sessions' => 4,
            'settlement_day' => 5,
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-08',
            'StartTime' => '18:00',
            'EndTime' => '20:00',
            'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-04-01',
            'StartTime' => '18:00',
            'EndTime' => '20:00',
            'Status' => 'scheduled',
        ]);
        $this->createConfirmedReport($student, $sc, [
            'payment_date' => '2026-04-10',
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/payments?branch_id=1&start=2026-04-01&end=2026-04-30');

        $res->assertOk()
            ->assertJsonPath('data.0.schedule_mode', 'date')
            ->assertJsonPath('data.0.first_session_date', '2026-04-01')
            ->assertJsonPath('data.0.is_prepaid', false);
    }

    public function test_accounting_ledger_aligns_invoice_payments_and_receipts(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8800, 'Paid' => 1]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-10',
            'TotalAmount' => 8800,
            'PaidAmount' => 8800,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);
        $paymentA = Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 5000,
            'PaidAt' => '2026-04-05',
            'Method' => 'cash',
            'Note' => '第一筆',
        ]);
        $reportA = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-04-05',
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-04-05 12:00:00'),
            'confirmed_by' => 1,
            'payment_id' => $paymentA->id,
            'report_token_hash' => hash('sha256', 'ledger-a-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);
        $paymentA->update(['payment_report_id' => $reportA->id]);

        $paymentB = Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 3800,
            'PaidAt' => '2026-04-06',
            'Method' => 'transfer',
            'Note' => '第二筆',
        ]);
        $reportB = PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-04-06',
            'payment_method' => 'transfer',
            'reported_amount' => 3800,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-04-06 12:00:00'),
            'confirmed_by' => 1,
            'payment_id' => $paymentB->id,
            'report_token_hash' => hash('sha256', 'ledger-b-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);
        $paymentB->update(['payment_report_id' => $reportB->id]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/accounting/ledger?branch_id=1&student_class_id={$sc->ID}");

        $res->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('summary.invoice_total', 8800)
            ->assertJsonPath('summary.applied_total', 8800)
            ->assertJsonPath('summary.outstanding_total', 0)
            ->assertJsonPath('summary.receipt_count', 2)
            ->assertJsonPath('summary.anomaly_count', 0)
            ->assertJsonPath('invoices.0.id', $invoice->id)
            ->assertJsonPath('invoices.0.invoice_no', 'INV-202604-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('invoices.0.calculated_applied_amount', 8800)
            ->assertJsonPath('invoices.0.overpaid_amount', 0)
            ->assertJsonPath('invoices.0.payments.0.receipt_no', 'RCPT-202604-' . str_pad((string) $reportA->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('invoices.0.payments.0.application_status', 'applied')
            ->assertJsonPath('invoices.0.payments.1.receipt_no', 'RCPT-202604-' . str_pad((string) $reportB->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('invoices.0.payments.1.application_status', 'applied');
    }

    public function test_accounting_ledger_caps_applied_amount_and_marks_excess_payment(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8800, 'Paid' => 1]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-10',
            'TotalAmount' => 8800,
            'PaidAmount' => 12800,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);

        foreach ([5000, 3800, 4000] as $idx => $amount) {
            $payment = Payment::create([
                'InvoiceID' => $invoice->id,
                'Amount' => $amount,
                'PaidAt' => '2026-04-' . str_pad((string) (5 + $idx), 2, '0', STR_PAD_LEFT),
                'Method' => $idx === 2 ? 'cash' : 'transfer',
                'Note' => '第' . ($idx + 1) . '筆',
            ]);
            $report = PaymentReport::create([
                'StudentID' => $student->id,
                'StudentClassID' => $sc->ID,
                'InvoiceID' => $invoice->id,
                'reported_by_name' => $student->name,
                'payment_date' => '2026-04-' . str_pad((string) (5 + $idx), 2, '0', STR_PAD_LEFT),
                'payment_method' => $idx === 2 ? 'cash' : 'transfer',
                'reported_amount' => $amount,
                'status' => 'confirmed',
                'confirmed_at' => Carbon::parse('2026-04-' . str_pad((string) (5 + $idx), 2, '0', STR_PAD_LEFT) . ' 12:00:00'),
                'confirmed_by' => 1,
                'payment_id' => $payment->id,
                'report_token_hash' => hash('sha256', 'ledger-overpay-' . $idx . '-' . uniqid()),
                'token_expires_at' => Carbon::now()->addDay(),
            ]);
            $payment->update(['payment_report_id' => $report->id]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/accounting/ledger?branch_id=1&student_class_id={$sc->ID}");

        $res->assertOk()
            ->assertJsonPath('summary.invoice_total', 8800)
            ->assertJsonPath('summary.applied_total', 8800)
            ->assertJsonPath('summary.overpaid_total', 4000)
            ->assertJsonPath('summary.outstanding_total', 0)
            ->assertJsonPath('invoices.0.calculated_applied_amount', 8800)
            ->assertJsonPath('invoices.0.overpaid_amount', 4000)
            ->assertJsonPath('invoices.0.payments.0.applied_amount', 5000)
            ->assertJsonPath('invoices.0.payments.0.application_status', 'applied')
            ->assertJsonPath('invoices.0.payments.1.applied_amount', 3800)
            ->assertJsonPath('invoices.0.payments.1.application_status', 'applied')
            ->assertJsonPath('invoices.0.payments.2.applied_amount', 0)
            ->assertJsonPath('invoices.0.payments.2.unapplied_amount', 4000)
            ->assertJsonPath('invoices.0.payments.2.application_status', 'overpayment_pending_review')
            ->assertJsonPath('anomalies.0.code', 'overpayment_pending_review');
    }

    public function test_accounting_settled_courses_includes_paid_count_course_without_low_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $student->update(['name' => '吳艾潼']);
        $sc = $this->createCountModeClass($student->id, [
            'Charge' => 8800,
            'Paid' => 1,
            'PayDate' => '2026-04-10',
            'RemainingSessions' => 8,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/settled-courses?branch_id=1&student=吳艾潼');

        $res->assertOk()
            ->assertJsonPath('summary.course_count', 1)
            ->assertJsonPath('summary.legacy_count', 1)
            ->assertJsonPath('data.0.student_class_id', $sc->ID)
            ->assertJsonPath('data.0.student_name', '吳艾潼')
            ->assertJsonPath('data.0.schedule_mode', 'count')
            ->assertJsonPath('data.0.legacy_paid_without_invoice', true);
    }

    public function test_accounting_settled_courses_marks_overpayment_exception(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $student->update(['name' => '吳艾潼']);
        $sc = $this->createCountModeClass($student->id, ['Charge' => 8800, 'Paid' => 1]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-10',
            'TotalAmount' => 8800,
            'PaidAmount' => 12800,
            'Status' => 'paid',
            'billing_period' => '2026-04',
        ]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 8800, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 4000, 'PaidAt' => '2026-04-11', 'Method' => 'cash']);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/settled-courses?branch_id=1&student=吳艾潼');

        $res->assertOk()
            ->assertJsonPath('summary.course_count', 1)
            ->assertJsonPath('summary.exception_count', 1)
            ->assertJsonPath('summary.overpaid_total', 4000)
            ->assertJsonPath('data.0.paid_amount', 8800)
            ->assertJsonPath('data.0.overpaid_amount', 4000)
            ->assertJsonPath('data.0.has_exception', true);
    }

    public function test_accounting_ledger_rejects_cross_campus_access(): void
    {
        $token = $this->createDirectorToken([2]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/accounting/ledger?branch_id=1&student_class_id={$sc->ID}");

        $res->assertStatus(403);
    }

    public function test_accounting_payments_rejects_cross_campus_branch(): void
    {
        $token = $this->createDirectorToken([2]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);
        $this->createConfirmedReport($student, $sc, [
            'payment_date' => '2026-04-10',
            'payment_method' => 'cash',
            'reported_amount' => 5000,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/payments?branch_id=1&start=2026-04-01&end=2026-04-30');

        $res->assertStatus(403);
    }

    public function test_accounting_payments_does_not_expose_account_last5(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createCountModeClass($student->id);
        $this->createConfirmedReport($student, $sc, [
            'payment_date' => '2026-04-10',
            'payment_method' => 'transfer',
            'reported_amount' => 8800,
            'account_last5' => '54321',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/accounting/payments/export?branch_id=1&start=2026-04-01&end=2026-04-30');

        $res->assertOk();
        $payload = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('54321', $payload);
        $this->assertArrayNotHasKey('account_last5', $res->json('data.0'));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director_' . uniqid() . '@test.com',
            'Name' => 'TestDirector',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);

        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => Carbon::now()->addDay(),
        ]);

        return $raw;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '測試學生' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'Phone' => '0912345678',
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
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
            'Charge' => 8800,
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
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createConfirmedReport(Student $student, StudentClass $studentClass, array $overrides = []): PaymentReport
    {
        return PaymentReport::create(array_merge([
            'StudentID' => $student->id,
            'StudentClassID' => $studentClass->ID,
            'reported_by_name' => $student->name,
            'payment_date' => '2026-04-10',
            'payment_method' => 'cash',
            'reported_amount' => 5000,
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-04-10 12:00:00'),
            'confirmed_by' => 1,
            'report_token_hash' => hash('sha256', 'accounting-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ], $overrides));
    }
}
