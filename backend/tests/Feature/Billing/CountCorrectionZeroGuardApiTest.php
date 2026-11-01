<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\BillingCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CountCorrectionZeroGuardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_confirm_reject_zero_without_changing_the_bill_and_audit_the_refusals(): void
    {
        $actor = User::create(['LoginName' => 'zero-guard-' . uniqid() . '@example.com', 'Name' => 'Synthetic', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $actor->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $actor->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'Synthetic', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-03-01', 'TotalHours' => 16, 'Charge' => 8000, 'Paid' => 0, 'Rate' => 0,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $item = InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'Synthetic', 'Amount' => 8000]);
        $before = [$course->fresh()->toArray(), $invoice->fresh()->toArray(), $item->fresh()->toArray()];
        $signed = app(BillingCorrection::class)->confirmationToken($course->fresh(), 3, 0, 0, [], (int) $actor->id);
        $body = ['new_session_count' => 3, 'new_charge' => 0, 'reason' => 'Synthetic zero charge'];

        foreach ([$body + ['preview' => true], $body + ['confirmation_token' => $signed]] as $payload) {
            $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", $payload)
                ->assertStatus(422)->assertJsonPath('code', 'billing_correction_zero_forbidden');
            $this->assertArrayNotHasKey('confirmation_token', $response->json());
            $this->assertSame($before, [$course->fresh()->toArray(), $invoice->fresh()->toArray(), $item->fresh()->toArray()]);
        }
        $rows = DB::table('security_audit_events')->where('event_type', 'student_class.edit_blocked')->where('outcome', 'blocked')->get();
        $this->assertCount(2, $rows->filter(fn ($row) => (json_decode($row->metadata, true)['reason_code'] ?? null) === 'billing_correction_zero_forbidden'));
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'student_class.billing_contract_correction')->count());
    }

    public function test_date_mode_correction_with_rate_zero_refuses_nt0_without_writes(): void
    {
        $actor = User::create(['LoginName' => 'zero-date-' . uniqid() . '@example.com', 'Name' => 'Synthetic', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $actor->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $actor->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'Synthetic', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-03-01', 'EndDate' => '2032-06-30', 'TotalHours' => 16, 'Charge' => 8000, 'Paid' => 0, 'Rate' => 0,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $before = [$course->fresh()->toArray(), $invoice->fresh()->toArray()];

        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", ['new_session_count' => 3, 'new_charge' => 0, 'reason' => 'Synthetic'])
            ->assertStatus(422)->assertJsonPath('code', 'billing_correction_zero_forbidden');
        $this->assertSame($before, [$course->fresh()->toArray(), $invoice->fresh()->toArray()], 'Rate 0 × 3 = 0 must not rewrite the bill to an unmarked NT$0');
    }
}
