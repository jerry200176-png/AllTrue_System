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

    public function test_partial_amendment_keeps_remaining_sessions_and_active_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Taipei'));
        try {
            [$token] = $this->director();
            $student = $this->student();
            $course = $this->course($student->id, [
                'SessionCount' => 4,
                'RemainingSessions' => 2,
                'UsedSessions' => 2,
            ]);
            for ($i = 1; $i <= 2; $i++) {
                $this->createClassSession($course->ID, "2026-09-0{$i}", 'attended');
            }
            $keepFuture = $this->createClassSession($course->ID, '2026-09-20', 'scheduled');
            $cancelFuture = $this->createClassSession($course->ID, '2026-09-27', 'scheduled');

            $this->withToken($token)->postJson(
                "/api/v1/student-classes/{$course->ID}/contract-amendment/preview",
                ['new_session_count' => 3]
            )->assertOk()
                ->assertJsonPath('new_remaining_sessions', 1)
                ->assertJsonPath('closes_contract', false)
                ->assertJsonPath('affected_future_scheduled_count', 1);

            $this->withToken($token)->postJson(
                "/api/v1/student-classes/{$course->ID}/contract-amendment",
                ['new_session_count' => 3, 'reason' => '調整本期合約總堂數']
            )->assertOk()
                ->assertJsonPath('after.SessionCount', 3)
                ->assertJsonPath('after.RemainingSessions', 1)
                ->assertJsonPath('after.Stop', 0)
                ->assertJsonPath('after.closed_reason', null);

            $course->refresh();
            $this->assertSame(1, (int) $course->RemainingSessions);
            $this->assertSame(0, (int) $course->Stop);
            $this->assertNull($course->closed_reason);
            $this->assertSame('scheduled', ClassSession::find($keepFuture->id)->Status);
            $this->assertSame('cancelled', ClassSession::find($cancelFuture->id)->Status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_full_early_close_sets_contract_amended_when_no_remaining(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Taipei'));
        try {
            [$token] = $this->director();
            $student = $this->student();
            $course = $this->course($student->id, [
                'SessionCount' => 4,
                'RemainingSessions' => 2,
                'UsedSessions' => 2,
            ]);
            for ($i = 1; $i <= 2; $i++) {
                $this->createClassSession($course->ID, "2026-09-0{$i}", 'attended');
            }
            $future = $this->createClassSession($course->ID, '2026-09-20', 'scheduled');

            $this->withToken($token)->postJson(
                "/api/v1/student-classes/{$course->ID}/contract-amendment",
                ['new_session_count' => 2, 'reason' => '確認提前結束']
            )->assertOk()
                ->assertJsonPath('after.RemainingSessions', 0)
                ->assertJsonPath('after.Stop', 1)
                ->assertJsonPath('after.closed_reason', 'contract_amended');

            $course->refresh();
            $this->assertSame('cancelled', ClassSession::find($future->id)->Status);
        } finally {
            Carbon::setTestNow();
        }
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

    public function test_revert_restores_contract_and_cancelled_sessions_then_rejects_second_revert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Taipei'));
        try {
            [$token] = $this->director();
            $course = $this->course($this->student()->id, ['SessionCount' => 8, 'RemainingSessions' => 8, 'UsedSessions' => 0, 'EndDate' => '2026-12-31']);
            $keep = $this->createClassSession($course->ID, '2026-09-20', 'scheduled');
            $future = $this->createClassSession($course->ID, '2026-09-27', 'scheduled');
            $url = "/api/v1/student-classes/{$course->ID}/contract-amendment";
            $this->withToken($token)->postJson($url, ['new_session_count' => 1, 'reason' => '調整'])->assertOk();
            $this->assertSame('cancelled', ClassSession::find($future->id)->Status);

            $this->withToken($token)->postJson("$url/revert/preview")->assertOk()
                ->assertJsonPath('restored_session_count', 8)
                ->assertJsonPath('restorable_sessions_count', 1)
                ->assertJsonPath('unscheduled_remaining_sessions', 6);
            $this->withToken($token)->postJson("$url/revert", [])->assertStatus(422);
            $this->withToken($token)->postJson("$url/revert", ['reason' => '誤操作'])->assertOk()
                ->assertJsonPath('after.SessionCount', 8)
                ->assertJsonPath('after.RemainingSessions', 8);

            $course->refresh();
            $this->assertSame(0, (int) $course->Stop);
            $this->assertNull($course->closed_reason);
            $this->assertSame('2026-12-31', substr((string) $course->EndDate, 0, 10));
            $this->assertSame('contract_amendment_reverted', json_decode($course->settlement_snapshot, true)['kind']);
            $restored = ClassSession::find($future->id);
            $this->assertSame('scheduled', $restored->Status);
            $this->assertSame('', trim((string) $restored->Note));
            $this->assertSame('scheduled', ClassSession::find($keep->id)->Status);
            $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.contract_amendment_reverted')->count());

            $this->withToken($token)->postJson("$url/revert", ['reason' => '再撤銷'])->assertStatus(409);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revert_rejects_drifted_contract(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Taipei'));
        try {
            [$token] = $this->director();
            $course = $this->course($this->student()->id, ['SessionCount' => 4, 'RemainingSessions' => 2, 'UsedSessions' => 2]);
            $url = "/api/v1/student-classes/{$course->ID}/contract-amendment";
            $this->withToken($token)->postJson($url, ['new_session_count' => 3, 'reason' => '調整'])->assertOk();
            $course->refresh()->update(['RemainingSessions' => 0]);

            $this->withToken($token)->postJson("$url/revert", ['reason' => '撤銷'])->assertStatus(409)
                ->assertJsonPath('message', '合約在調整後已有變動，無法自動撤銷，請聯絡管理員。');
            $this->assertSame(3, (int) $course->fresh()->SessionCount);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revert_rejects_when_cancelled_slot_is_now_occupied(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Taipei'));
        try {
            [$token] = $this->director();
            $student = $this->student();
            $course = $this->course($student->id, ['SessionCount' => 4, 'RemainingSessions' => 2, 'UsedSessions' => 2]);
            $future = $this->createClassSession($course->ID, '2026-09-27', 'scheduled');
            $url = "/api/v1/student-classes/{$course->ID}/contract-amendment";
            $this->withToken($token)->postJson($url, ['new_session_count' => 2, 'reason' => '提前結束'])->assertOk();
            $other = $this->course($student->id, ['SubjectID' => 2]);
            $this->createClassSession($other->ID, '2026-09-27', 'scheduled');

            $this->withToken($token)->postJson("$url/revert", ['reason' => '撤銷'])->assertStatus(409);
            $course->refresh();
            $this->assertSame(1, (int) $course->Stop);
            $this->assertSame('contract_amended', $course->closed_reason);
            $this->assertSame('cancelled', ClassSession::find($future->id)->Status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revert_is_forbidden_for_teacher_role(): void
    {
        $user = User::create(['LoginName' => 't-' . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0900000001', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $course = $this->course($this->student()->id);
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/contract-amendment/revert", ['reason' => 'x'])->assertStatus(403);
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
