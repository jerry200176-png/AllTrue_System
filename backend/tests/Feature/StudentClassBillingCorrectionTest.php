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
        ClassSession::create([
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

        $response = $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/billing-correction",
            [
                'new_session_count' => 4,
                'new_charge' => 4400,
                'reason' => '主任確認本期理化實際上四堂',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('new_session_count', 4)
            ->assertJsonPath('new_charge', 4400)
            ->assertJsonPath('remaining_sessions', 0)
            ->assertJsonPath('payment_status', 'unpaid');

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
