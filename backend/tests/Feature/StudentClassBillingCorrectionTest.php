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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentClassBillingCorrectionTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_confirmation_uses_the_same_quota_sequence_when_cancelled_and_leave_history_exist(): void
    {
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
