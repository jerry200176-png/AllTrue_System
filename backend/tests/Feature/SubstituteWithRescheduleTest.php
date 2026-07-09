<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 代課 + 換時間合併操作 PRD f0cce4d5 — Feature 測試套件
 *
 * 涵蓋（FR-001~FR-009）：
 *  1. 合併代課+換時（成功）：ClassSession 時間 / LR / schedules / 通知一致更新
 *  2. 合併代課+換時（跨分校衝堂被擋 422）：新時段為準
 *  3. 合併代課+換時（同分校衝堂被擋 409）：新時段為準
 *  4. Undo 還原時間 + 老師 + 通知作廢
 *  5. 純代課回歸（無 new_date 時原路徑不變）
 *  6. 半填欄位（僅 new_date 或僅 new_start_time）→ 422
 *  7. 新日期為過去日期 → 422
 *  8. 近期代課記錄含 operation_type 欄位
 */
class SubstituteWithRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────
    // 1. 合併代課 + 換時（成功）
    // ───────────────────────────────────────────────────────────
    public function test_combined_substitute_with_reschedule_success(): void
    {
        [$dirToken, $oldId, $subId, $session, $campusId] = $this->seedScenarioSingleCampus();

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => '正班老師請假 + 教室檔期',
            'new_date' => '2026-04-20',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ]);

        $res->assertOk()
            ->assertJsonFragment([
                'operation_type' => 'substitute_with_reschedule',
                'rescheduled' => true,
                'session_date' => '2026-04-20',
                'start_time' => '14:00',
                'end_time' => '16:00',
                'original_session_date' => '2026-04-19',
                'original_start_time' => '13:00',
                'original_end_time' => '15:00',
            ]);

        // ClassSession 已更新至新時段
        $session->refresh();
        $this->assertEquals('2026-04-20', \Carbon\Carbon::parse($session->SessionDate)->toDateString());
        $this->assertEquals('14:00', substr((string) $session->StartTime, 0, 5));
        $this->assertEquals('16:00', substr((string) $session->EndTime, 0, 5));

        // schedules 列已遷移到新日期（start/end 以 HH:MM 儲存）
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-20',
            'status' => 'rescheduled',
            'start_time' => '14:00',
            'end_time' => '16:00',
        ]);
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-20',
            'status' => 'scheduled',
            'teacher_id' => $subId,
            'start_time' => '14:00',
            'end_time' => '16:00',
        ]);

        // 通知 Title / Body 使用「課程異動通知」格式
        $notif = Notification::where('SourceType', 'ClassSession')->where('SourceID', $session->id)->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('課程異動通知', (string) $notif->Title);
        $this->assertStringContainsString('原定 2026-04-19 13:00~15:00', (string) $notif->Body);
        $this->assertStringContainsString('已調整至 2026-04-20 14:00~16:00', (string) $notif->Body);
        $this->assertEquals('substitute_with_reschedule', $notif->Payload['operation_type'] ?? '');
        $this->assertEquals('2026-04-19', $notif->Payload['original_session_date'] ?? '');
        $this->assertEquals('13:00', $notif->Payload['original_start_time'] ?? '');
        $this->assertEquals('15:00', $notif->Payload['original_end_time'] ?? '');
    }

    // ───────────────────────────────────────────────────────────
    // 2. 合併時以「新時段」檢查跨分校衝堂 → 422
    // ───────────────────────────────────────────────────────────
    public function test_combined_blocked_by_cross_campus_conflict_on_new_slot(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        // 代課老師在另一分校 2026-04-20 14:00-16:00 已有課 → 合併新時段應被跨分校擋下
        $otherCampusId = 2;
        UserCampus::create(['CampusID' => $otherCampusId, 'UserID' => $subId, 'Admin' => 0, 'Approved' => 1]);
        $otherStudent = Student::create([
            'name' => '他校學生B', 'CampusID' => $otherCampusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $otherSc = StudentClass::create([
            'StudentID' => $otherStudent->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $subId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $otherSc->ID, 'SessionDate' => '2026-04-20',
            'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_date' => '2026-04-20',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ]);

        $res->assertStatus(422)->assertJsonFragment(['cross_campus' => true]);

        // 原 ClassSession 時間未被變動
        $session->refresh();
        $this->assertEquals('2026-04-19', \Carbon\Carbon::parse($session->SessionDate)->toDateString());
        $this->assertEquals('13:00', substr((string) $session->StartTime, 0, 5));
        // 未發送任何通知
        $this->assertDatabaseMissing('Notifications', [
            'Type' => 'substitute',
            'SourceType' => 'ClassSession',
            'SourceID' => (string) $session->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 3. 合併時以「新時段」檢查同分校容量衝堂 → 409
    // ───────────────────────────────────────────────────────────
    public function test_combined_blocked_by_same_campus_conflict_on_new_slot(): void
    {
        [$dirToken, , $subId, $session, $campusId] = $this->seedScenarioSingleCampus();

        // 代課老師在同分校 2026-04-20 14:00-16:00 已有一堂 schedules（正班）
        Schedule::create([
            'student_id' => 999, 'teacher_id' => $subId, 'subject' => 'Math',
            'day_of_week' => 1, 'start_time' => '14:00', 'end_time' => '16:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 0, 'branch_id' => $campusId,
            'schedule_date' => '2026-04-20', 'student_course_id' => 8888,
        ]);

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_date' => '2026-04-20',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ]);

        // 注意：同分校若老師綁定目前分校，ScheduleGuardService 回 409
        // （跨分校條件會先被 array_filter 排除，不會走 422 分支）
        $res->assertStatus(409);

        // ClassSession 時間未被變動
        $session->refresh();
        $this->assertEquals('2026-04-19', \Carbon\Carbon::parse($session->SessionDate)->toDateString());
    }

    // ───────────────────────────────────────────────────────────
    // 4. Undo：還原 ClassSession 時間 + 老師 + 通知作廢
    // ───────────────────────────────────────────────────────────
    public function test_undo_restores_class_session_time_after_combined_op(): void
    {
        [$dirToken, $oldId, $subId, $session] = $this->seedScenarioSingleCampus();

        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_date' => '2026-04-20',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ])->assertOk();

        // 確認已搬到新時段
        $session->refresh();
        $this->assertEquals('2026-04-20', \Carbon\Carbon::parse($session->SessionDate)->toDateString());

        // 執行 Undo
        $undo = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute/undo");
        $undo->assertOk()
            ->assertJsonFragment([
                'restored_time' => true,
                'restored_session_date' => '2026-04-19',
                'restored_start_time' => '13:00',
                'restored_end_time' => '15:00',
                'restored_teacher_id' => $oldId,
            ]);

        // ClassSession 時間還原
        $session->refresh();
        $this->assertEquals('2026-04-19', \Carbon\Carbon::parse($session->SessionDate)->toDateString());
        $this->assertEquals('13:00', substr((string) $session->StartTime, 0, 5));
        $this->assertEquals('15:00', substr((string) $session->EndTime, 0, 5));

        // LearningRecord 老師與時間還原
        $lr = LearningRecord::where('ClassSessionID', $session->id)->first();
        $this->assertNotNull($lr);
        $this->assertEquals($oldId, (int) $lr->TeacherID);
        $this->assertEquals('2026-04-19', \Carbon\Carbon::parse($lr->SessionDate)->toDateString());

        // 通知 Payload 標記為 voided
        $notif = Notification::where('SourceType', 'ClassSession')->where('SourceID', $session->id)->first();
        $this->assertNotNull($notif->ResolvedAt);
        $this->assertTrue((bool) ($notif->Payload['voided'] ?? false));

        // schedules 代課列已刪除
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-20',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 5. 純代課回歸：未提供 new_date 時行為與現行一致
    // ───────────────────────────────────────────────────────────
    public function test_pure_substitute_regression_no_reschedule_fields(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => '純代課',
        ]);

        $res->assertOk()
            ->assertJsonFragment([
                'operation_type' => 'substitute',
                'rescheduled' => false,
                'session_date' => '2026-04-19',
                'start_time' => '13:00',
                'end_time' => '15:00',
            ]);

        // ClassSession 時間未被變更（回歸保護）
        $session->refresh();
        $this->assertEquals('2026-04-19', \Carbon\Carbon::parse($session->SessionDate)->toDateString());
        $this->assertEquals('13:00', substr((string) $session->StartTime, 0, 5));

        // 通知使用舊有「代課通知」Title
        $notif = Notification::where('SourceType', 'ClassSession')->where('SourceID', $session->id)->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('代課通知', (string) $notif->Title);
        $this->assertStringNotContainsString('課程異動通知', (string) $notif->Title);
        $this->assertEquals('substitute', $notif->Payload['operation_type'] ?? '');
    }

    public function test_substitute_repairs_unanchored_scheduled_row_after_prior_reschedule(): void
    {
        [$dirToken, $oldId, $subId, $session] = $this->seedScenarioSingleCampus();
        $courseId = (int) $session->StudentClassID;
        $studentId = (int) StudentClass::where('ID', $courseId)->value('StudentID');

        $session->SessionDate = '2026-04-19';
        $session->StartTime = '10:00';
        $session->EndTime = '12:00';
        $session->save();

        $anchor = Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => $oldId,
            'subject' => 'Math',
            'day_of_week' => 7,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-19',
            'student_course_id' => $courseId,
        ]);
        $ghost = Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => $subId,
            'subject' => 'Math',
            'day_of_week' => 7,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-19',
            'student_course_id' => $courseId,
            'original_schedule_id' => null,
        ]);

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => 'repair existing chained substitute row',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('schedules', [
            'id' => $ghost->id,
            'student_course_id' => $courseId,
            'status' => 'scheduled',
            'teacher_id' => $subId,
            'start_time' => '10:00',
            'original_schedule_id' => $anchor->id,
        ]);
        $this->assertSame(0, Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereNull('original_schedule_id')
            ->count());
        $this->assertSame(1, Schedule::where('student_course_id', $courseId)
            ->where('status', 'rescheduled')
            ->where('teacher_id', $oldId)
            ->count());
    }

    public function test_backfill_substitute_anchors_repairs_historical_null_anchor_rows(): void
    {
        [, $oldId, $subId, $session] = $this->seedScenarioSingleCampus();
        $courseId = (int) $session->StudentClassID;
        $studentId = (int) StudentClass::where('ID', $courseId)->value('StudentID');

        $anchor = Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => $oldId,
            'subject' => 'Math',
            'day_of_week' => 7,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-19',
            'student_course_id' => $courseId,
        ]);
        $ghostAnchor = Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => $subId,
            'subject' => 'Math',
            'day_of_week' => 7,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-19',
            'student_course_id' => $courseId,
        ]);
        $scheduled = Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => $subId,
            'subject' => 'Math',
            'day_of_week' => 7,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-19',
            'student_course_id' => $courseId,
            'original_schedule_id' => null,
        ]);

        $this->artisan('schedules:backfill-substitute-anchors', ['--date' => '2026-04-19'])
            ->assertExitCode(0);
        $this->assertDatabaseHas('schedules', [
            'id' => $scheduled->id,
            'original_schedule_id' => null,
        ]);
        $this->assertDatabaseHas('schedules', [
            'id' => $ghostAnchor->id,
            'status' => 'rescheduled',
        ]);

        $this->artisan('schedules:backfill-substitute-anchors', [
            '--date' => '2026-04-19',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('schedules', [
            'id' => $scheduled->id,
            'original_schedule_id' => $anchor->id,
        ]);
        $this->assertDatabaseMissing('schedules', [
            'id' => $ghostAnchor->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 6. 半填欄位 → 422（FR-003）
    // ───────────────────────────────────────────────────────────
    public function test_partial_reschedule_fields_rejected(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        // 只填 new_date，缺 new_start_time / new_end_time
        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_date' => '2026-04-20',
        ]);
        $res->assertStatus(422);

        // 只填 new_start_time，缺 new_date
        $res2 = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ]);
        $res2->assertStatus(422);
    }

    // ───────────────────────────────────────────────────────────
    // 7. 新日期為過去 → 422（資安/防呆）
    // ───────────────────────────────────────────────────────────
    public function test_new_date_in_past_rejected(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_date' => '2020-01-01',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ]);
        $res->assertStatus(422);
    }

    // ───────────────────────────────────────────────────────────
    // 8. 近期代課記錄 API 回傳 operation_type 欄位
    // ───────────────────────────────────────────────────────────
    public function test_recent_substitutes_returns_operation_type(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'new_date' => '2026-04-20',
            'new_start_time' => '14:00',
            'new_end_time' => '16:00',
        ])->assertOk();

        $res = $this->withAuth($dirToken)->getJson('/api/v1/substitutes/recent');
        $res->assertOk();

        $items = $res->json('items');
        $this->assertGreaterThanOrEqual(1, count($items));
        $target = collect($items)->firstWhere('class_session_id', $session->id);
        $this->assertNotNull($target);
        $this->assertEquals('substitute_with_reschedule', $target['operation_type']);
        $this->assertEquals('2026-04-19', $target['original_session_date']);
    }

    // ──────────── helpers ────────────

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 回傳：[$dirToken, $regularTeacherId, $subTeacherId, ClassSession, $campusId]
     * - 正班/代課老師皆綁在 campus 1
     * - 代課 session 於 2026-04-19 13:00-15:00
     */
    private function seedScenarioSingleCampus(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-combo@example.com', 'Name' => '主任合併', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900000201', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular-combo@example.com', 'Name' => '正班合併', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000202', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-combo@example.com', 'Name' => '代課合併', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000203', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '合併測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $regular->id, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => '2026-04-19', 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        return [$dirToken, (int) $regular->id, (int) $sub->id, $session, $campusId];
    }
}
