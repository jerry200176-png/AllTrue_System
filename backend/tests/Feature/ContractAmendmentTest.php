<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Schedule;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractAmendmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_execute_reduces_count_without_target_or_financial_mutation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Charge' => 8800, 'Paid' => 0]);
        for ($i = 1; $i <= 3; $i++) $this->createClassSession($course->ID, "2026-09-0{$i}", 'attended');
        $future = $this->createClassSession($course->ID, '2026-09-10', 'scheduled');
        $futureSchedule = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => 99, 'subject' => '數學',
            'day_of_week' => 4, 'start_time' => '15:00', 'end_time' => '17:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'schedule_date' => '2026-09-10', 'student_course_id' => $course->ID,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/contract-amendment/preview",
            ['new_session_count' => 3]
        )->assertOk()
            ->assertJsonPath('original_session_count', 8)
            ->assertJsonPath('new_session_count', 3)
            ->assertJsonPath('completed_sessions', 3)
            ->assertJsonPath('new_remaining_sessions', 0)
            ->assertJsonPath('affected_future_scheduled_count', 1)
            ->assertJsonPath('affected_future_schedules_count', 1)
            ->assertJsonPath('financial_mutation', 'none');

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/contract-amendment",
            ['new_session_count' => 3, 'reason' => '學生不再續上']
        )->assertOk()
            ->assertJsonPath('after.SessionCount', 3)
            ->assertJsonPath('after.RemainingSessions', 0)
            ->assertJsonPath('financial_mutation', 'none');

        $course->refresh();
        $this->assertSame(3, (int) $course->SessionCount);
        $this->assertSame(0, (int) $course->RemainingSessions);
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('contract_amended', $course->closed_reason);
        $this->assertSame(8800, (int) $course->Charge);
        $this->assertSame('unpaid', Invoice::find($invoice->id)->Status);
        $this->assertSame('cancelled', ClassSession::find($future->id)->Status);
        $this->assertSame('cancelled', Schedule::find($futureSchedule->id)->status);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.contract_amendment')->count());

        // in-app #251: unpaid amended contracts must remain actionable in tuition alerts
        $alert = $this->withToken($token)->getJson('/api/v1/alerts/tuition?branch_id=1');
        $alert->assertOk();
        $match = collect($alert->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($match, 'unpaid contract_amended course must appear in tuition alerts');
        $this->assertSame('pending_reconciliation', $match['payment_status'] ?? null);

        Carbon::setTestNow();
    }

    public function test_paid_contract_amendment_does_not_enter_pending_reconciliation_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Charge' => 8800, 'Paid' => 1, 'PayDate' => '2026-09-01']);
        for ($i = 1; $i <= 3; $i++) {
            $this->createClassSession($course->ID, "2026-09-0{$i}", 'attended');
        }

        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/contract-amendment",
            ['new_session_count' => 3, 'reason' => '已繳費提前結束']
        )->assertOk();

        $course->refresh();
        $this->assertSame('contract_amended', $course->closed_reason);
        $this->assertSame(1, (int) $course->Paid);

        $alert = $this->withToken($token)->getJson('/api/v1/alerts/tuition?branch_id=1');
        $alert->assertOk();
        $match = collect($alert->json())->firstWhere('id', $course->ID);
        $this->assertNull($match, 'paid contract_amended course must not enter unpaid reconciliation queue');
        Carbon::setTestNow();
    }

    public function test_cannot_reduce_below_completed_usage_and_does_not_require_target(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id);
        for ($i = 1; $i <= 3; $i++) $this->createClassSession($course->ID, "2026-09-0{$i}", 'attended');
        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/contract-amendment/preview",
            ['new_session_count' => 2]
        )->assertStatus(422);
        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/contract-amendment",
            ['new_session_count' => 2, 'reason' => '測試']
        )->assertStatus(422);
        $this->assertSame(8, (int) $course->fresh()->SessionCount);
    }

    public function test_transfer_route_remains_target_required_and_separate(): void
    {
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id);
        $session = $this->createClassSession($course->ID, '2026-09-01', 'attended');
        $this->withToken($token)->postJson(
            "/api/v1/student-classes/{$course->ID}/transfer-sessions",
            ['session_ids' => [$session->id]]
        )->assertStatus(422);
        $this->assertSame($course->ID, (int) ClassSession::find($session->id)->StudentClassID);
    }

    private function director(): array
    {
        $user = User::create(['LoginName' => 'amend-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$token, (int) $user->id];
    }

    private function student(): Student
    {
        return Student::create(['name' => '測試學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge(['StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-01', 'Charge' => 8800, 'Paid' => 0, 'Rate' => 1100, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'TotalHours' => 16, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0], $overrides));
    }

    private function createClassSession(int $courseId, string $date, string $status): ClassSession
    {
        return ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => $date, 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => $status]);
    }
}
