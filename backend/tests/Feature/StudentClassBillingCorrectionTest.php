<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentReport;
use App\Models\SecurityAuditEvent;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentClassBillingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Retirable surplus reservations are scheduled rows dated on or after
     * "today" in the app timezone (Asia/Taipei). Tests whose fixtures use
     * literal future dates pin the clock before them; otherwise they go red
     * once Taipei's calendar passes a fixture date (2026-10-16 00:00 Taipei =
     * 2026-10-15T16:00Z broke the quota-sequence test). Laravel's tearDown
     * resets the test clock.
     */
    private function pinClockBeforeFixtureDates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00', 'Asia/Taipei'));
    }

    public function test_full_receipt_reversal_allows_an_eligible_trial_obligation_correction_and_repayment(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 500, 'Charge' => 8000, 'SessionCount' => 16,
            'RemainingSessions' => 16, 'UsedSessions' => 0, 'TotalHours' => 32]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID,
            'Description' => 'Synthetic trial correction', 'Amount' => 8000]);
        $body = ['student_class_id' => $course->ID, 'invoice_id' => $invoice->id,
            'payment_date' => '2026-09-02', 'payment_method' => 'cash', 'amount' => 8000];
        $original = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/confirm")->assertOk();
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/void", ['void_reason' => 'Synthetic mistaken receipt'])->assertOk();
        $correction = ['new_session_count' => 1, 'new_charge' => 500, 'reason' => 'Synthetic verified trial obligation'];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", $correction + ['preview' => true])->assertOk();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction",
            $correction + ['confirmation_token' => $preview->json('confirmation_token')])->assertOk();
        $this->assertSame([8000, -8000], DB::table('Payment')->where('InvoiceID', $invoice->id)->orderBy('id')->pluck('Amount')->map(fn ($v) => (int) $v)->all());
        $body['amount'] = 500;
        $replacement = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $this->assertSame(2, DB::table('Payment')->where('InvoiceID', $invoice->id)->count());
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$replacement}/confirm")->assertOk();
        $this->assertSame([8000, -8000, 500], DB::table('Payment')->where('InvoiceID', $invoice->id)->orderBy('id')->pluck('Amount')->map(fn ($v) => (int) $v)->all());
        $this->assertDatabaseHas('payment_reports', ['id' => $original, 'status' => 'voided']);
        $this->assertDatabaseHas('Invoice', ['id' => $invoice->id, 'TotalAmount' => 500, 'PaidAmount' => 500]);
    }

    public function test_reject_and_replace_pending_amount_does_not_post_money_before_confirmation(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Charge' => 8800]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $body = ['student_class_id' => $course->ID, 'invoice_id' => $invoice->id,
            'payment_date' => '2026-09-02', 'payment_method' => 'cash', 'amount' => 8000];
        $original = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/reject", ['rejection_note' => 'Synthetic wrong reported amount'])->assertOk();
        $body['amount'] = 8800;
        $replacement = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $this->assertSame(0, DB::table('Payment')->where('InvoiceID', $invoice->id)->count());
        $this->assertDatabaseHas('payment_reports', ['id' => $original, 'status' => 'rejected', 'reported_amount' => 8000]);
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$replacement}/confirm")->assertOk();
        $this->assertSame([8800], DB::table('Payment')->where('InvoiceID', $invoice->id)->pluck('Amount')->map(fn ($v) => (int) $v)->all());
    }

    public function test_selected_invoice_cap_is_not_bypassed_by_another_invoice(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Charge' => 16800]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-02', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID, 'invoice_id' => $invoice->id, 'amount' => 8800,
            'payment_date' => '2026-09-02', 'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->assertSame(0, DB::table('Payment')->count());
        $this->assertSame(0, PaymentReport::where('StudentClassID', $course->ID)->count());
        $this->assertSame(8000, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_partial_reversal_after_preview_still_blocks_the_locked_correction(): void
    {
        [$token, $actorId] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 500, 'Charge' => 8000, 'SessionCount' => 16,
            'RemainingSessions' => 16, 'UsedSessions' => 0]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $correction = ['new_session_count' => 1, 'new_charge' => 500, 'reason' => 'Synthetic correction'];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", $correction + ['preview' => true])->assertOk();
        foreach ([8000, -7500] as $amount) {
            DB::table('Payment')->insert(['InvoiceID' => $invoice->id, 'Amount' => $amount,
                'Method' => $amount < 0 ? 'void' : 'cash', 'PaidAt' => '2026-09-02']);
        }
        try {
            app(\App\Services\Billing\BillingCorrection::class)->applyCountCorrection(
                $course, 1, 500, 'Synthetic correction', 16, 8000, $preview->json('confirmation_token'),
                $actorId
            );
            $this->fail('A surviving receipt must block the locked correction');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_payment_locked', json_decode($exception->getResponse()->getContent(), true)['code']);
        }
        $this->assertSame(8000, (int) $course->fresh()->Charge);
        $this->assertSame(8000, (int) $invoice->fresh()->TotalAmount);
    }

    /**
     * PR #4217 P1: after a matched full reversal, multiple non-void invoices must not each
     * receive the full corrected charge (BillingPayableResolver sums them additively).
     */
    public function test_multiple_open_invoices_after_matched_reversal_refuse_duplicate_full_charge(): void
    {
        [$token, $actorId] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 500, 'Charge' => 8000, 'SessionCount' => 16,
            'RemainingSessions' => 16, 'UsedSessions' => 0, 'TotalHours' => 32]);
        $invoiceA = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoiceA->id, 'StudentClassID' => $course->ID,
            'Description' => 'Synthetic primary bill', 'Amount' => 8000]);
        $invoiceB = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-02', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoiceB->id, 'StudentClassID' => $course->ID,
            'Description' => 'Synthetic second open bill', 'Amount' => 8000]);

        $body = ['student_class_id' => $course->ID, 'invoice_id' => $invoiceA->id,
            'payment_date' => '2026-09-02', 'payment_method' => 'cash', 'amount' => 8000];
        $original = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/confirm")->assertOk();
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/void", ['void_reason' => 'Synthetic mistaken receipt'])->assertOk();

        $this->assertFalse(\App\Services\Billing\ContractMoneyState::blocksUnpaidCorrection((int) $course->ID));
        $this->assertSame(2, Invoice::query()->where('StudentClassID', $course->ID)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->count());

        $correction = ['new_session_count' => 1, 'new_charge' => 500, 'reason' => 'Synthetic multi-invoice refuse'];
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", $correction + ['preview' => true])
            ->assertStatus(409)->assertJsonPath('code', 'billing_correction_multiple_open_invoices');

        // Locked write path: a token bound to the current ledger must still refuse before stamping both bills.
        $service = app(\App\Services\Billing\BillingCorrection::class);
        $validToken = $service->confirmationToken($course->fresh(), 1, 500, 0, [], $actorId);
        try {
            $service->applyCountCorrection($course->fresh(), 1, 500, 'Synthetic multi-invoice refuse', 16, 8000, $validToken, $actorId);
            $this->fail('Multiple open invoices must refuse the locked correction');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_multiple_open_invoices', json_decode($exception->getResponse()->getContent(), true)['code']);
        }

        $this->assertSame(8000, (int) $course->fresh()->Charge);
        $this->assertSame(16, (int) $course->fresh()->SessionCount);
        $this->assertSame(8000, (int) $invoiceA->fresh()->TotalAmount);
        $this->assertSame(8000, (int) $invoiceB->fresh()->TotalAmount);
        $this->assertSame(0, DB::table('security_audit_events')
            ->where('event_type', 'student_class.billing_contract_correction')->count());
    }

    /**
     * PR #4217 P2: preview token must bind ledger/report history and be recomputed under
     * payment locks so a confirm→void cycle after preview invalidates the confirmation.
     */
    public function test_preview_token_invalidates_after_payment_void_cycle_under_locks(): void
    {
        [$token, $actorId] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 500, 'Charge' => 8000, 'SessionCount' => 16,
            'RemainingSessions' => 16, 'UsedSessions' => 0, 'TotalHours' => 32]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID,
            'Description' => 'Synthetic never-paid then void-cycled', 'Amount' => 8000]);

        $correction = ['new_session_count' => 1, 'new_charge' => 500, 'reason' => 'Synthetic ledger-bound preview'];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", $correction + ['preview' => true])->assertOk();
        $previewToken = (string) $preview->json('confirmation_token');
        $this->assertNotSame('', $previewToken);

        // Another operator records, confirms, then fully voids a receipt before the first operator submits.
        $body = ['student_class_id' => $course->ID, 'invoice_id' => $invoice->id,
            'payment_date' => '2026-09-03', 'payment_method' => 'cash', 'amount' => 8000];
        $reportId = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$reportId}/confirm")->assertOk();
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'Synthetic void after preview'])->assertOk();

        $this->assertFalse(\App\Services\Billing\ContractMoneyState::blocksUnpaidCorrection((int) $course->ID));

        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction",
            $correction + ['confirmation_token' => $previewToken])
            ->assertStatus(409)->assertJsonPath('code', 'billing_correction_confirmation_required');

        try {
            app(\App\Services\Billing\BillingCorrection::class)->applyCountCorrection(
                $course->fresh(), 1, 500, 'Synthetic ledger-bound preview', 16, 8000, $previewToken, $actorId
            );
            $this->fail('A preview token from before the void cycle must be stale under payment locks');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_confirmation_stale', json_decode($exception->getResponse()->getContent(), true)['code']);
        }

        $this->assertSame(8000, (int) $course->fresh()->Charge);
        $this->assertSame(16, (int) $course->fresh()->SessionCount);
        $this->assertSame(8000, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame(0, DB::table('security_audit_events')
            ->where('event_type', 'student_class.billing_contract_correction')->count());
    }

    public function test_monthly_reversed_receipt_keeps_both_date_correction_paths_blocked_without_repricing(): void
    {
        // PR4217: a real confirmation snapshot and billable sessions must not be bypassed by changing stored totals.
        Carbon::setTestNow('2026-09-30 12:00:00');
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-09-01',
            'EndDate' => '2026-09-30', 'Rate' => 2000, 'Charge' => 8000, 'SessionCount' => 4,
            'RemainingSessions' => 4, 'monthly_sessions' => 4, 'settlement_day' => 30]);
        foreach (['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date,
                'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        $body = ['student_class_id' => $course->ID, 'payment_date' => '2026-09-30',
            'payment_method' => 'cash', 'amount' => 8000];
        $original = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', $body)->assertOk()->json('report_id');
        $confirmed = $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/confirm")->assertOk();
        $invoice = Invoice::findOrFail($confirmed->json('invoice_id'));
        $this->assertSame(4, (int) $invoice->billing_snapshot['period_sessions']);
        $this->assertCount(4, $invoice->billing_snapshot['sessions']);
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$original}/void",
            ['void_reason' => 'Synthetic mistaken monthly receipt'])->assertOk();
        $snapshot = $invoice->fresh()->billing_snapshot;
        $history = DB::table('Payment')->where('InvoiceID', $invoice->id)->orderBy('id')->get()->toArray();
        $reportCount = PaymentReport::where('StudentClassID', $course->ID)->count();

        // This separate date-mode path was already conservative before PR4217; it is not widened here.
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction",
            ['new_session_count' => 3, 'new_charge' => 6000, 'new_end_date' => '2026-09-30',
                'reason' => 'Synthetic date boundary correction'])->assertStatus(409)->assertJsonPath('code', 'billing_correction_payment_locked');
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/charge-correction",
            ['new_charge' => 500, 'reason' => 'Synthetic verified charge'])->assertStatus(409)->assertJsonPath('code', 'charge_correction_payment_locked');
        $this->assertSame(8000, (int) $course->fresh()->Charge);
        $this->assertSame(4, (int) $course->fresh()->SessionCount);
        $this->assertSame('2026-09-30', Carbon::parse($course->fresh()->EndDate)->toDateString());
        $this->assertSame(8000, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame($snapshot, $invoice->fresh()->billing_snapshot);
        $this->assertEquals($history, DB::table('Payment')->where('InvoiceID', $invoice->id)->orderBy('id')->get()->toArray());
        $this->assertSame($reportCount, PaymentReport::where('StudentClassID', $course->ID)->count());

        // The unchanged authoritative monthly amount can still be recorded and confirmed separately.
        $replacement = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record',
            $body + ['invoice_id' => $invoice->id])->assertOk()->json('report_id');
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$replacement}/confirm")->assertOk();
        $this->assertSame(8000, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame([8000, -8000, 8000], DB::table('Payment')->where('InvoiceID', $invoice->id)->orderBy('id')->pluck('Amount')->map(fn ($v) => (int) $v)->all());
    }

    public function test_editability_preflight_explains_locked_contract_and_safe_actions(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, [
            'UsedSessions' => 1,
            'RemainingSessions' => 7,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-05-01',
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'attended',
        ]);
        SessionDeductionLedger::create([
            'student_class_id' => $course->ID,
            'class_session_id' => $session->id,
            'event_type' => 'deduct',
            'source' => 'attendance',
            'minutes' => 120,
        ]);

        $this->withToken($token)->getJson(
            "/api/v1/student-classes/{$course->ID}/editability"
        )->assertOk()
            ->assertJsonPath('course_id', $course->ID)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('locked_fields.0', 'sessions_purchased')
            ->assertJsonFragment(['code' => 'billing_contract_locked'])
            ->assertJsonPath('available_actions.1', 'billing_correction')
            ->assertJsonPath('available_actions.2', 'transfer_sessions');

        $this->withToken($token)->putJson(
            "/api/v1/student-classes/{$course->ID}",
            ['sessions_purchased' => 6]
        )->assertStatus(422)->assertJsonPath('code', 'billing_contract_locked');

        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'student_class.edit_blocked',
            'outcome' => 'blocked',
        ]);
    }

    public function test_director_can_correct_unpaid_count_course_after_deduction(): void
    {
        $this->pinClockBeforeFixtureDates();
        [$token, $userId] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, [
            'TotalHours' => 10,
            'Charge' => 5500,
            'SessionCount' => 5,
            'RemainingSessions' => 5,
        ]);

        $attended = [];
        for ($i = 1; $i <= 4; $i++) {
            $attended[] = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => "2026-05-{$i}",
                'StartTime' => '15:00',
                'EndTime' => '17:00',
                'Status' => 'attended',
            ]);
        }
        $futureScheduled = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-10-17',
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'scheduled',
        ]);
        SessionDeductionLedger::create([
            'student_class_id' => $course->ID,
            'class_session_id' => $attended[0]->id,
            'event_type' => 'deduct',
            'source' => 'attendance',
            'minutes' => 120,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-08-12',
            'TotalAmount' => 5500,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '高中理化',
            'Amount' => 5500,
        ]);

        $preview = $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/billing-correction",
            [
                'new_session_count' => 4,
                'new_charge' => 4400,
                'reason' => '主任確認本期理化實際上四堂',
                'preview' => true,
            ]
        );

        $preview->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('affected_scheduled_sessions.0.session_id', $futureScheduled->id)
            ->assertJsonPath('new_session_count', 4);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $course->ID,
            'SessionCount' => 5,
            'Charge' => 5500,
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-10-17',
            'Status' => 'scheduled',
        ]);

        $response = $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/billing-correction",
            [
                'new_session_count' => 4,
                'new_charge' => 4400,
                'reason' => '主任確認本期理化實際上四堂',
                'confirmation_token' => $preview->json('confirmation_token'),
            ]
        );

        $response->assertOk()
            ->assertJsonPath('new_session_count', 4)
            ->assertJsonPath('new_charge', 4400)
            ->assertJsonPath('remaining_sessions', 0)
            ->assertJsonPath('payment_status', 'unpaid')
            ->assertJsonPath('cancelled_scheduled_sessions.0.session_id', $futureScheduled->id);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $course->ID,
            'SessionCount' => 4,
            'Charge' => 4400,
            'Paid' => 0,
            'RemainingSessions' => 0,
            'UsedSessions' => 4,
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-10-17',
            'Status' => 'cancelled',
        ]);
        $this->assertSame(0, DB::table('payment_reports')->where('StudentClassID', $course->ID)->count());
        $this->assertDatabaseHas('Invoice', [
            'id' => $invoice->id,
            'TotalAmount' => 4400,
        ]);
        $this->assertDatabaseHas('InvoiceItem', [
            'InvoiceID' => $invoice->id,
            'Amount' => 4400,
        ]);

        // Replaying the same confirmation after the contract changed must not
        // make a second correction or recreate the cancelled future slot.
        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/billing-correction",
            [
                'new_session_count' => 4,
                'new_charge' => 4400,
                'reason' => '主任確認本期理化實際上四堂',
                'confirmation_token' => $preview->json('confirmation_token'),
            ]
        )->assertStatus(422)->assertJsonPath('code', 'billing_correction_reduction_only');
        $this->assertSame(1, DB::table('security_audit_events')
            ->where('event_type', 'student_class.billing_contract_correction')->count());

        $audit = DB::table('security_audit_events')
            ->where('event_type', 'student_class.billing_contract_correction')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(SecurityAuditEvent::ref('user', $userId), $audit->actor_ref);
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(5, (int) $metadata['old_session_count']);
        $this->assertSame(4, (int) $metadata['new_session_count']);
        $this->assertSame(5500, (int) $metadata['old_charge']);
        $this->assertSame(4400, (int) $metadata['new_charge']);
    }

    public function test_correction_cannot_reduce_below_observed_usage(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id);

        for ($i = 1; $i <= 7; $i++) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => "2026-05-{$i}",
                'StartTime' => '15:00',
                'EndTime' => '17:00',
                'Status' => 'attended',
            ]);
        }

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/billing-correction",
            ['new_session_count' => 6, 'new_charge' => 6600, 'reason' => '測試低於已使用']
        )->assertStatus(422)
            ->assertJsonPath('code', 'billing_correction_below_observed_usage')
            ->assertJsonPath('next_step', 'edit_charge_only');

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $course->ID,
            'SessionCount' => 8,
            'Charge' => 8800,
        ]);
    }

    public function test_correction_rejects_paid_and_pending_report_courses(): void
    {
        [$token] = $this->director();
        $student = $this->student();

        $paid = $this->course($student->id, ['Paid' => 1]);
        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$paid->ID}/billing-correction",
            ['new_session_count' => 7, 'new_charge' => 7700, 'reason' => '測試已收款']
        )->assertStatus(409)->assertJsonPath('code', 'billing_correction_paid_locked');

        $pending = $this->course($student->id);
        PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $pending->ID,
            'reported_by_name' => $student->name,
            'payment_date' => now()->toDateString(),
            'reported_amount' => 8800,
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'billing-correction-pending-' . uniqid()),
            'token_expires_at' => now()->addDay(),
        ]);
        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$pending->ID}/billing-correction",
            ['new_session_count' => 7, 'new_charge' => 7700, 'reason' => '測試待對帳']
        )->assertStatus(409)->assertJsonPath('code', 'billing_correction_payment_report_locked');
    }

    public function test_confirmation_rechecks_payment_report_created_after_preview_without_partial_write(): void
    {
        $this->pinClockBeforeFixtureDates();
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['SessionCount' => 4, 'Charge' => 4400, 'RemainingSessions' => 3, 'UsedSessions' => 1]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15'] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled']);
        }

        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認未收款三堂', 'preview' => true,
        ])->assertOk();
        PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'reported_by_name' => $student->name,
            'payment_date' => now()->toDateString(), 'reported_amount' => 3300, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'after-preview-' . uniqid()), 'token_expires_at' => now()->addDay(),
        ]);

        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認未收款三堂',
            'confirmation_token' => $preview->json('confirmation_token'),
        ])->assertStatus(409)->assertJsonPath('code', 'billing_correction_payment_report_locked');

        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 4, 'Charge' => 4400]);
        $this->assertSame(3, ClassSession::where('StudentClassID', $course->ID)->where('Status', 'scheduled')->count());
        $this->assertSame(0, DB::table('security_audit_events')
            ->where('event_type', 'student_class.billing_contract_correction')->count());
    }

    public function test_four_to_three_after_one_attended_cancels_tail_through_confirmed_api_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00', 'Asia/Taipei'));
        try {
            [$token] = $this->director();
            $student = $this->student();
            $course = $this->course($student->id, ['SessionCount' => 4, 'Charge' => 4400, 'RemainingSessions' => 3, 'UsedSessions' => 1]);
            $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
            SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
            $scheduled = collect(['2026-09-01', '2026-09-08', '2026-09-15'])->map(fn (string $date) => ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled',
            ]));

            $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
                'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認四堂改為三堂', 'preview' => true,
            ])->assertOk()->assertJsonPath('affected_scheduled_sessions.0.session_id', $scheduled->last()->id);
            $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
                'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認四堂改為三堂',
                'confirmation_token' => $preview->json('confirmation_token'),
            ])->assertOk()->assertJsonPath('remaining_sessions', 2);

            $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 3, 'Charge' => 3300, 'UsedSessions' => 1, 'RemainingSessions' => 2]);
            $this->assertDatabaseHas('ClassSession', ['id' => $scheduled->last()->id, 'Status' => 'cancelled']);
            $this->assertSame(2, ClassSession::where('StudentClassID', $course->ID)->where('Status', 'scheduled')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_confirmation_uses_the_same_quota_sequence_when_cancelled_and_leave_history_exist(): void
    {
        $this->pinClockBeforeFixtureDates();
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, [
            'SessionCount' => 4,
            'Charge' => 4400,
            'RemainingSessions' => 3,
            'UsedSessions' => 1,
        ]);

        // These historical rows must remain untouched and must not shift the
        // retained purchased-session sequence used by preview or confirmation.
        $cancelled = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-04-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'cancelled']);
        $leave = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-04-08', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'leave']);
        $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        $scheduled = collect(['2026-10-01', '2026-10-08', '2026-10-15'])->map(fn (string $date) => ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled',
        ]));

        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認取消與請假歷史不佔本期堂數', 'preview' => true,
        ])->assertOk();
        $preview->assertJsonPath('affected_scheduled_sessions.0.session_id', $scheduled->last()->id)
            ->assertJsonCount(1, 'affected_scheduled_sessions');

        $confirmed = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認取消與請假歷史不佔本期堂數',
            'confirmation_token' => $preview->json('confirmation_token'),
        ])->assertOk();
        $confirmed->assertJsonPath('cancelled_scheduled_sessions.0.session_id', $scheduled->last()->id)
            ->assertJsonCount(1, 'cancelled_scheduled_sessions');

        $this->assertDatabaseHas('ClassSession', ['id' => $cancelled->id, 'Status' => 'cancelled']);
        $this->assertDatabaseHas('ClassSession', ['id' => $leave->id, 'Status' => 'leave']);
        $this->assertDatabaseHas('ClassSession', ['id' => $attended->id, 'Status' => 'attended']);
        $this->assertDatabaseHas('ClassSession', ['id' => $scheduled->last()->id, 'Status' => 'cancelled']);
        $this->assertSame(2, ClassSession::whereIn('id', $scheduled->take(2)->pluck('id'))->where('Status', 'scheduled')->count());
    }

    /**
     * Regression for the 2026-10-15T16:00Z CI break: whether the tail
     * reservation is retired depends only on its Taipei calendar date versus
     * Taipei "today", never on the runner's wall clock or UTC date. Dated
     * today it is retired exactly as previewed; dated yesterday and never
     * recorded, the correction is refused (fail closed).
     *
     * @dataProvider taipeiMidnightBoundaryProvider
     */
    public function test_tail_reservation_retirement_follows_the_taipei_calendar_day(string $utcNow, bool $tailIsRetirable): void
    {
        Carbon::setTestNow(Carbon::parse($utcNow, 'UTC'));

        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, [
            'SessionCount' => 4,
            'Charge' => 4400,
            'RemainingSessions' => 3,
            'UsedSessions' => 1,
        ]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-04-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'cancelled']);
        $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        $scheduled = collect(['2026-10-01', '2026-10-08', '2026-10-15'])->map(fn (string $date) => ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled',
        ]));

        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認跨午夜邊界', 'preview' => true,
        ]);

        if (!$tailIsRetirable) {
            // Fail closed (Founder 2026-10-16): a tail dated yesterday (Taipei)
            // that was never recorded blocks the reduction; nothing is written.
            $preview->assertStatus(422)
                ->assertJsonPath('code', 'billing_correction_past_unrecorded_sessions')
                ->assertJsonCount(1, 'past_unrecorded_sessions')
                ->assertJsonPath('past_unrecorded_sessions.0.session_id', $scheduled->last()->id);
            $this->assertDatabaseHas('ClassSession', ['id' => $scheduled->last()->id, 'Status' => 'scheduled']);
            $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 4, 'Charge' => 4400]);
            $this->assertSame(3, ClassSession::whereIn('id', $scheduled->pluck('id'))->where('Status', 'scheduled')->count());

            return;
        }

        $preview->assertOk()
            ->assertJsonCount(1, 'affected_scheduled_sessions')
            ->assertJsonPath('affected_scheduled_sessions.0.session_id', $scheduled->last()->id);

        // The confirmed write retires exactly what the preview listed.
        $confirmed = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 3, 'new_charge' => 3300, 'reason' => '主任確認跨午夜邊界',
            'confirmation_token' => $preview->json('confirmation_token'),
        ])->assertOk();
        $this->assertSame($preview->json('affected_scheduled_sessions'), $confirmed->json('cancelled_scheduled_sessions'));
        $this->assertDatabaseHas('ClassSession', ['id' => $scheduled->last()->id, 'Status' => 'cancelled']);
        $this->assertSame(2, ClassSession::whereIn('id', $scheduled->take(2)->pluck('id'))->where('Status', 'scheduled')->count());
    }

    /** @return array<string, array{string, bool}> */
    public function taipeiMidnightBoundaryProvider(): array
    {
        return [
            '2026-10-15 23:59 Taipei: tail dated today is retirable' => ['2026-10-15T15:59:00Z', true],
            '2026-10-16 00:01 Taipei: unrecorded tail dated yesterday blocks the reduction' => ['2026-10-15T16:01:00Z', false],
        ];
    }

    public function test_director_can_correct_unpaid_date_mode_charge_without_touching_entitlement(): void
    {
        [$token, $userId] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, [
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'UsedSessions' => 2,
            'Charge' => 4400,
            'Rate' => 1100,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-08-25',
            'TotalAmount' => 4400,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
        InvoiceItem::create([
            'InvoiceID' => $invoice->id,
            'StudentClassID' => $course->ID,
            'Description' => '數學 8 月',
            'Amount' => 4400,
        ]);

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/charge-correction",
            ['new_charge' => 6500, 'reason' => '主任確認八月五堂新制費用']
        )->assertOk()
            ->assertJsonPath('old_charge', 4400)
            ->assertJsonPath('new_charge', 6500)
            ->assertJsonPath('used_sessions', 2)
            ->assertJsonPath('remaining_sessions', 0)
            ->assertJsonPath('adjusted_invoice_count', 1)
            ->assertJsonPath('payment_status', 'unpaid');

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $course->ID,
            'Charge' => 6500,
            'SessionCount' => 0,
            'UsedSessions' => 2,
            'RemainingSessions' => 0,
        ]);
        $this->assertDatabaseHas('Invoice', ['id' => $invoice->id, 'TotalAmount' => 6500]);
        $this->assertDatabaseHas('InvoiceItem', ['InvoiceID' => $invoice->id, 'Amount' => 6500]);

        $audit = DB::table('security_audit_events')
            ->where('event_type', 'student_class.date_mode_charge_correction')
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(SecurityAuditEvent::ref('user', $userId), $audit->actor_ref);
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(4400, (int) $metadata['old_charge']);
        $this->assertSame(6500, (int) $metadata['new_charge']);
        $this->assertSame(hash('sha256', '主任確認八月五堂新制費用'), $metadata['reason_hash']);
    }

    public function test_date_mode_transfer_reconciliation_syncs_contract_and_records_cash_without_reviving_void_invoice(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-08-28',
            'SessionCount' => 4,
            'RemainingSessions' => 0,
            'UsedSessions' => 5,
            'Charge' => 7200,
            'Rate' => 1800,
        ]);
        foreach (['2026-08-01', '2026-08-08', '2026-08-15', '2026-08-22', '2026-08-29'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => 'attended',
            ]);
        }
        $voidInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-08-01',
            'TotalAmount' => 6600,
            'PaidAmount' => 0,
            'Status' => 'void',
            'billing_period' => '2026-07',
        ]);

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/billing-correction",
            [
                'new_session_count' => 5,
                'new_charge' => 9000,
                'new_end_date' => '2026-08-29',
                'reason' => 'in-app-259-august-session-transfer',
            ]
        )->assertOk()
            ->assertJsonPath('new_session_count', 5)
            ->assertJsonPath('new_charge', 9000)
            ->assertJsonPath('new_end_date', '2026-08-29')
            ->assertJsonPath('remaining_sessions', 0)
            ->assertJsonPath('adjusted_invoice_count', 0);

        $course->refresh();
        $this->assertSame(5, (int) $course->SessionCount);
        $this->assertSame(9000, (int) $course->Charge);
        $this->assertSame('2026-08-29', substr((string) $course->EndDate, 0, 10));
        $this->assertDatabaseHas('Invoice', [
            'id' => $voidInvoice->id, 'Status' => 'void', 'TotalAmount' => 6600, 'PaidAmount' => 0,
        ]);

        $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID,
            'invoice_id' => $voidInvoice->id,
            'payment_date' => '2026-09-04',
            'payment_method' => 'cash',
            'amount' => 9000,
        ])->assertStatus(422)->assertJsonPath('code', 'void_invoice_cannot_record');

        $invoice = $this->withToken($token)->postJson('/api/v1/invoices', [
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-04',
            'TotalAmount' => 9000,
            'billing_period' => '2026-08',
            'Items' => [[
                'StudentClassID' => $course->ID,
                'Description' => '2026 年 8 月數學',
                'Amount' => 9000,
                'PeriodStart' => '2026-08-01',
                'PeriodEnd' => '2026-08-29',
            ]],
        ])->assertCreated()->json();

        $record = $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID,
            'invoice_id' => $invoice['id'],
            'payment_date' => '2026-09-04',
            'payment_method' => 'cash',
            'amount' => 9000,
            'note' => '主任 9/4 現金收訖；in-app #259',
        ])->assertOk()->json();

        $this->withToken($token)->putJson('/api/v1/payment-reports/' . $record['report_id'] . '/confirm')
            ->assertOk();

        $this->assertDatabaseHas('Invoice', [
            'id' => $invoice['id'], 'Status' => 'paid', 'TotalAmount' => 9000, 'PaidAmount' => 9000,
        ]);
        $this->assertDatabaseHas('Payment', [
            'InvoiceID' => $invoice['id'], 'Amount' => 9000, 'Method' => 'cash',
        ]);
        $this->assertDatabaseHas('payment_reports', [
            'id' => $record['report_id'], 'StudentClassID' => $course->ID, 'status' => 'confirmed',
        ]);
        $this->assertSame(2, Invoice::where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, (int) Invoice::where('StudentClassID', $course->ID)->where('Status', 'void')->sum('PaidAmount'));
        $this->assertSame(1, (int) $course->fresh()->Paid);
    }

    public function test_charge_correction_rejects_count_mode_courses(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id);

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/charge-correction",
            ['new_charge' => 7700, 'reason' => '測試']
        )->assertStatus(422)->assertJsonPath('code', 'charge_correction_date_mode_only');

        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'Charge' => 8800]);
    }

    /** @return array{0:string,1:int} */
    private function director(): array
    {
        $user = User::create([
            'LoginName' => 'billing-correction-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return [$token, (int) $user->id];
    }

    private function student(): Student
    {
        return Student::create([
            'name' => '洪睿淵測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function course(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'TotalHours' => 16,
            'Charge' => 8800,
            'Paid' => 0,
            'Rate' => 1100,
            'rate_unit' => 'session',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
