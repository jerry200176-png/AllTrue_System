<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\ExceptionWorkflow;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ParentLeaveCutoffTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ClassSession::flushEventListeners();
        parent::tearDown();
    }

    /** Requirement 1: > 24h 正常請假成功 */
    public function test_leave_request_greater_than_24h_succeeds_as_standard_leave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));
        [, , $session] = $this->makeStudentCourseSession(1, '大於24h學生', '0912111001', '2026-05-03', '14:00', '16:00');
        $token = $this->parentLogin('大於24h學生', '0912111001');

        $res = $this->postLeave($token, $session->id, '家庭旅遊提前請假');
        $res->assertOk()
            ->assertJsonPath('message', '請假申請已送出。')
            ->assertJsonPath('is_late', false)
            ->assertJsonPath('leave_type', 'standard')
            ->assertJsonPath('workflow.type', 'student_leave')
            ->assertJsonPath('workflow.is_late', false)
            ->assertJsonPath('workflow.leave_type', 'standard')
            ->assertJsonPath('session.status', 'leave_requested');

        $this->assertDatabaseHas('exception_workflows', [
            'class_session_id' => $session->id,
            'type' => 'student_leave',
            'status' => 'open',
            'severity' => 'medium',
        ]);
        $workflow = ExceptionWorkflow::where('class_session_id', $session->id)->firstOrFail();
        $this->assertFalse($workflow->payload['is_late'] ?? true);
        $this->assertSame('standard', $workflow->payload['leave_type'] ?? null);
        $this->assertSame('家庭旅遊提前請假', $workflow->payload['reason'] ?? null);
        $this->assertDatabaseHas('ClassSession', ['id' => $session->id, 'Status' => 'leave_requested']);
    }

    /** Requirement 2: < 24h、未開課 → 可送臨時請假並有正確提示/metadata */
    public function test_leave_request_less_than_24h_before_start_succeeds_as_late_leave_with_metadata(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));
        [, , $session] = $this->makeStudentCourseSession(1, '小於24h學生', '0912111002', '2026-05-02', '08:00', '10:00');
        $token = $this->parentLogin('小於24h學生', '0912111002');

        $res = $this->postLeave($token, $session->id, '臨時突發腸胃炎');
        $res->assertOk()
            ->assertJsonPath('is_late', true)
            ->assertJsonPath('leave_type', 'late')
            ->assertJsonPath('workflow.is_late', true)
            ->assertJsonPath('workflow.leave_type', 'late')
            ->assertJsonPath('session.status', 'leave_requested');
        $this->assertStringContainsString('臨時請假', (string) $res->json('message'));
        $this->assertStringContainsString('超過一般請假期限', (string) $res->json('notice'));

        $workflow = ExceptionWorkflow::where('class_session_id', $session->id)->firstOrFail();
        $this->assertTrue($workflow->payload['is_late'] ?? false);
        $this->assertSame('late', $workflow->payload['leave_type'] ?? null);
        $this->assertStringContainsString('超過一般請假期限', (string) ($workflow->payload['notice'] ?? ''));
        $this->assertNotEmpty($workflow->payload['cutoff_at'] ?? null);
        $this->assertNotEmpty($workflow->payload['session_start_at'] ?? null);
        $this->assertSame('high', $workflow->severity);
    }

    /** Requirement 3: 已達開始時間 → backend 拒絕 */
    public function test_leave_request_at_or_after_start_time_is_rejected_by_backend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));

        // Case 3a: Exactly at start time
        [, , $atStart] = $this->makeStudentCourseSession(1, '準時學生', '0912111003', '2026-05-01', '10:00', '12:00');
        $tokenA = $this->parentLogin('準時學生', '0912111003');
        $resA = $this->postLeave($tokenA, $atStart->id, '剛好準時請假');
        $resA->assertStatus(422)->assertJsonPath('error_code', 'SESSION_ALREADY_STARTED');
        $this->assertStringContainsString('已達或超過開課時間', (string) $resA->json('message'));
        $this->assertDatabaseMissing('exception_workflows', ['class_session_id' => $atStart->id]);
        $this->assertDatabaseHas('ClassSession', ['id' => $atStart->id, 'Status' => 'scheduled']);

        // Case 3b: Past start time
        [, , $past] = $this->makeStudentCourseSession(1, '過期學生', '0912111004', '2026-05-01', '09:00', '11:00');
        $tokenB = $this->parentLogin('過期學生', '0912111004');
        $resB = $this->postLeave($tokenB, $past->id, '事後請假');
        $resB->assertStatus(422)->assertJsonPath('error_code', 'SESSION_ALREADY_STARTED');
        $this->assertDatabaseMissing('exception_workflows', ['class_session_id' => $past->id]);
    }

    /** Requirement 4: rescheduled session 使用實際新時段計算 */
    public function test_rescheduled_session_uses_actual_new_slot_for_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));

        // Case 4a: Rescheduled to 4 days ahead (>24h)
        [, , $sFuture] = $this->makeStudentCourseSession(1, '調課學生A', '0912111005', '2026-05-05', '14:00', '16:00', 'rescheduled');
        $this->postLeave($this->parentLogin('調課學生A', '0912111005'), $sFuture->id, '調課後仍需請假')
            ->assertOk()->assertJsonPath('message', '請假申請已送出。')
            ->assertJsonPath('is_late', false)->assertJsonPath('leave_type', 'standard');

        // Case 4b: Rescheduled to within 24h (<24h)
        [, , $sLate] = $this->makeStudentCourseSession(1, '調課學生B', '0912111006', '2026-05-01', '18:00', '20:00', 'rescheduled');
        $this->postLeave($this->parentLogin('調課學生B', '0912111006'), $sLate->id, '臨時調課後有事')
            ->assertOk()->assertJsonPath('is_late', true)->assertJsonPath('leave_type', 'late');

        // Case 4c: Rescheduled to already started slot
        [, , $sStarted] = $this->makeStudentCourseSession(1, '調課學生C', '0912111007', '2026-05-01', '08:30', '10:30', 'rescheduled');
        $this->postLeave($this->parentLogin('調課學生C', '0912111007'), $sStarted->id, '已開始調課堂次')
            ->assertStatus(422)->assertJsonPath('error_code', 'SESSION_ALREADY_STARTED');
    }

    /** Requirement 5: timezone / midnight boundary 正確 */
    public function test_timezone_and_midnight_boundary_correctness(): void
    {
        // Session at 2026-05-02 00:30:00. Cutoff is 2026-05-01 00:30:00 Asia/Taipei.
        // 5a: 1s before cutoff -> standard
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:29:59', 'Asia/Taipei'));
        [, , $s1] = $this->makeStudentCourseSession(1, '午夜學生1', '0912111008', '2026-05-02', '00:30', '02:00');
        $this->postLeave($this->parentLogin('午夜學生1', '0912111008'), $s1->id)->assertOk()
            ->assertJsonPath('message', '請假申請已送出。')
            ->assertJsonPath('is_late', false)->assertJsonPath('leave_type', 'standard');

        // 5b: Exactly at 24h cutoff -> late
        Carbon::setTestNow(Carbon::parse('2026-05-01 00:30:00', 'Asia/Taipei'));
        [, , $s2] = $this->makeStudentCourseSession(1, '午夜學生2', '0912111009', '2026-05-02', '00:30', '02:00');
        $this->postLeave($this->parentLogin('午夜學生2', '0912111009'), $s2->id)->assertOk()
            ->assertJsonPath('is_late', true)->assertJsonPath('leave_type', 'late');

        // 5c: 1s before midnight start -> late
        Carbon::setTestNow(Carbon::parse('2026-05-02 00:29:59', 'Asia/Taipei'));
        [, , $s3] = $this->makeStudentCourseSession(1, '午夜學生3', '0912111010', '2026-05-02', '00:30', '02:00');
        $t3 = $this->parentLogin('午夜學生3', '0912111010');
        $this->postLeave($t3, $s3->id)->assertOk()
            ->assertJsonPath('is_late', true)->assertJsonPath('leave_type', 'late');

        // 5d: At midnight start -> rejected
        Carbon::setTestNow(Carbon::parse('2026-05-02 00:30:00', 'Asia/Taipei'));
        $this->postLeave($t3, $s3->id)->assertStatus(422)
            ->assertJsonPath('error_code', 'SESSION_ALREADY_STARTED');
    }

    /** Requirement 6: repeated submit 不產生 duplicate request */
    public function test_repeated_submit_is_idempotent_and_does_not_duplicate_workflow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));

        // Case 6a: standard leave idempotency
        [, , $sStd] = $this->makeStudentCourseSession(1, '重複學生A', '0912111011', '2026-05-04', '14:00', '16:00');
        $tA = $this->parentLogin('重複學生A', '0912111011');
        $r1 = $this->postLeave($tA, $sStd->id, '第一次點擊')->assertOk();
        $r2 = $this->postLeave($tA, $sStd->id, '第二次點擊')->assertOk();
        $this->assertSame((int) $r1->json('workflow.id'), (int) $r2->json('workflow.id'));
        $this->assertSame(1, ExceptionWorkflow::where('class_session_id', $sStd->id)->count());

        // Case 6b: late leave idempotency
        [, , $sLate] = $this->makeStudentCourseSession(1, '重複學生B', '0912111012', '2026-05-01', '18:00', '20:00');
        $tB = $this->parentLogin('重複學生B', '0912111012');
        $r3 = $this->postLeave($tB, $sLate->id, '臨時請假首次')->assertOk()->assertJsonPath('is_late', true);
        $r4 = $this->postLeave($tB, $sLate->id, '臨時請假重送')->assertOk()->assertJsonPath('is_late', true);
        $this->assertSame((int) $r3->json('workflow.id'), (int) $r4->json('workflow.id'));
        $this->assertSame(1, ExceptionWorkflow::where('class_session_id', $sLate->id)->count());
    }

    /** Concurrency Requirement A: Locked race condition at/after start returns error_code SESSION_ALREADY_STARTED */
    public function test_locked_row_race_at_or_after_start_time_returns_session_already_started_error_code(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 09:59:59', 'Asia/Taipei'));
        [, , $session] = $this->makeStudentCourseSession(1, '並發學生A', '0912111020', '2026-05-01', '10:00', '12:00');
        $token = $this->parentLogin('並發學生A', '0912111020');

        $retrieveCount = 0;
        ClassSession::retrieved(function ($model) use (&$retrieveCount, $session) {
            if ($model->id === $session->id) {
                $retrieveCount++;
                if ($retrieveCount === 2) {
                    // Advance time to start time right as lockForUpdate query resolves
                    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));
                }
            }
        });

        $res = $this->postLeave($token, $session->id, '並發鎖定測試');
        $res->assertStatus(422)
            ->assertJsonPath('error_code', 'SESSION_ALREADY_STARTED');
        $this->assertStringContainsString('已達或超過開課時間', (string) $res->json('message'));

        $this->assertDatabaseMissing('exception_workflows', ['class_session_id' => $session->id]);
        $this->assertDatabaseHas('ClassSession', ['id' => $session->id, 'Status' => 'scheduled']);
    }

    /** Concurrency Requirement B: Locked row recomputes session start, cutoff, and all metadata when updated concurrently */
    public function test_locked_row_recomputes_session_start_cutoff_and_metadata_when_rescheduled_during_lock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));
        // Initial session is > 24h away (2026-05-03 14:00, would be standard leave)
        [, , $session] = $this->makeStudentCourseSession(1, '並發學生B', '0912111021', '2026-05-03', '14:00', '16:00');
        $token = $this->parentLogin('並發學生B', '0912111021');

        $retrieveCount = 0;
        ClassSession::retrieved(function ($model) use (&$retrieveCount, $session) {
            if ($model->id === $session->id) {
                $retrieveCount++;
                if ($retrieveCount === 1) {
                    // Update DB directly so lockForUpdate query retrieves the newly rescheduled slot
                    ClassSession::where('id', $session->id)->update([
                        'SessionDate' => '2026-05-01',
                        'StartTime' => '18:00',
                        'EndTime' => '20:00',
                        'Status' => 'rescheduled',
                    ]);
                }
            }
        });

        $res = $this->postLeave($token, $session->id, '調課後鎖定重新計算');
        $res->assertOk()
            ->assertJsonPath('is_late', true)
            ->assertJsonPath('leave_type', 'late')
            ->assertJsonPath('workflow.is_late', true)
            ->assertJsonPath('workflow.leave_type', 'late');

        $workflow = ExceptionWorkflow::where('class_session_id', $session->id)->firstOrFail();
        $this->assertTrue($workflow->payload['is_late']);
        $this->assertSame('late', $workflow->payload['leave_type']);
        $this->assertSame('2026-05-01', $workflow->payload['session_date']);
        $this->assertSame('18:00', $workflow->payload['start_time']);
        $this->assertStringContainsString('2026-05-01T18:00:00', $workflow->payload['session_start_at']);
        $this->assertStringContainsString('2026-04-30T18:00:00', $workflow->payload['cutoff_at']);
        $this->assertSame('high', $workflow->severity);
    }

    /** Helper verification: Dashboard projection includes cutoff and past/late flags */
    public function test_parent_dashboard_projection_includes_past_and_late_flags(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00:00', 'Asia/Taipei'));
        [, , $sPast] = $this->makeStudentCourseSession(1, '儀表板學生', '0912111013', '2026-05-01', '09:00', '11:00');
        [, , $sLate] = $this->makeStudentCourseSession(1, '儀表板學生', '0912111013', '2026-05-01', '18:00', '20:00');
        [, , $sFuture] = $this->makeStudentCourseSession(1, '儀表板學生', '0912111013', '2026-05-05', '14:00', '16:00');

        $token = $this->parentLogin('儀表板學生', '0912111013');
        $res = $this->getJson('/api/v1/parent/dashboard', ['Authorization' => "Bearer {$token}"])->assertOk();
        $upcoming = collect($res->json('upcoming_sessions'));

        $pPast = $upcoming->firstWhere('id', $sPast->id);
        $this->assertTrue($pPast['is_past_or_started']);
        $this->assertFalse($pPast['is_late_leave']);

        $pLate = $upcoming->firstWhere('id', $sLate->id);
        $this->assertFalse($pLate['is_past_or_started']);
        $this->assertTrue($pLate['is_late_leave']);

        $pFuture = $upcoming->firstWhere('id', $sFuture->id);
        $this->assertFalse($pFuture['is_past_or_started']);
        $this->assertFalse($pFuture['is_late_leave']);
    }

    private function postLeave(string $token, int $sessionId, string $reason = ''): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/parent/sessions/{$sessionId}/leave", ['reason' => $reason], ['Authorization' => "Bearer {$token}"]);
    }

    private function makeStudentCourseSession(
        int $campusId, string $name, string $phone, string $date = '2026-05-06',
        string $start = '18:30', string $end = '20:30', string $status = 'scheduled'
    ): array {
        $student = Student::firstOrCreate(['Phone' => $phone], [
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 1, 'SchoolName' => '測試學校', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-05-01', 'EndDate' => '2026-06-30', 'TotalHours' => 8, 'Charge' => 8800, 'Paid' => 1, 'Rate' => 1100,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => $start, 'EndTime' => $end, 'Status' => $status,
        ]);
        return [$student, $course, $session];
    }

    private function parentLogin(string $name, string $phone): string
    {
        $res = $this->postJson('/api/v1/parent/login', ['Name' => $name, 'Phone' => $phone]);
        $res->assertOk();
        return (string) $res->json('token');
    }
}
