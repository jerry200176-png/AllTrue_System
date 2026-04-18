<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 代課流程 UX 優化 PRD 9c058f19 — Feature 測試套件
 *
 * 涵蓋（FR-001~FR-012）：
 *  1. 單堂代課成功 + 建立家長通知（FR-001/FR-002/FR-010）
 *  2. 同分校時段衝突 → 409（回歸保護）
 *  3. 跨分校（物理不可分身）衝堂 → 422 + conflicts[]（FR-004a）
 *  4. Undo 單堂代課：回復 schedules / LearningRecord + voided 通知（FR-005/FR-011）
 *  5. 老師請假 Preview：回傳區間內受影響堂次（FR-006）
 *  6. 老師請假批次代課：全成功 summary（FR-007~FR-009）
 *  7. 批次含有衝堂堂次（atomic=true） → 422 整批回滾（FR-009）
 *  8. GET /teachers/{id}/availability → 跨分校 busy_slots 僅含 time+campus（第 9 節 Info Disclosure）
 *  9. GET /substitutes/recent?branch_id= → 分校隔離（FR-012 / US-05）
 */
class SubstituteUxV2Test extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────────────────────────────────
    // 1. 單堂代課成功 + 家長 Notification 於同一交易建立
    // ───────────────────────────────────────────────────────────
    public function test_single_substitute_creates_parent_notification(): void
    {
        [$dirToken, $oldId, $subId, $session, $campusId] = $this->seedScenarioSingleCampus();

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => '正班老師請假',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['notification_id', 'undo_deadline_ms', 'cross_campus', 'substitute_teacher_id'])
            ->assertJsonFragment(['substitute_teacher_id' => $subId, 'cross_campus' => false]);

        $this->assertDatabaseHas('Notifications', [
            'Type' => 'substitute',
            'SourceType' => 'ClassSession',
            'SourceID' => (string) $session->id,
            'CampusID' => $campusId,
        ]);

        $notif = Notification::where('SourceType', 'ClassSession')->where('SourceID', $session->id)->first();
        $this->assertNotNull($notif);
        $this->assertNull($notif->ResolvedAt);
        $this->assertEquals($oldId, (int) ($notif->Payload['old_teacher_id'] ?? 0));
        $this->assertEquals($subId, (int) ($notif->Payload['new_teacher_id'] ?? 0));
        $this->assertEquals('正班老師請假', (string) ($notif->Payload['reason'] ?? ''));
    }

    // ───────────────────────────────────────────────────────────
    // 2. 跨分校（物理不可分身）衝堂 → 422 + conflicts[]
    // ───────────────────────────────────────────────────────────
    public function test_cross_campus_conflict_blocks_substitute(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        // 讓 $subId 在「另一分校」同時段已有一堂正班課 → detectCrossCampusConflict 應阻擋
        $otherCampusId = 2;
        UserCampus::create(['CampusID' => $otherCampusId, 'UserID' => $subId, 'Admin' => 0, 'Approved' => 1]);
        $otherStudent = Student::create([
            'name' => '他校學生', 'CampusID' => $otherCampusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $otherSc = StudentClass::create([
            'StudentID' => $otherStudent->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $subId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $otherSc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment(['cross_campus' => true])
            ->assertJsonStructure(['conflicts' => [['start_time', 'end_time', 'campus_id']]]);

        // 未發送任何 Notification
        $this->assertDatabaseMissing('Notifications', [
            'Type' => 'substitute',
            'SourceType' => 'ClassSession',
            'SourceID' => (string) $session->id,
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 3. Undo：回復 schedules / LearningRecord + voided 通知
    // ───────────────────────────────────────────────────────────
    public function test_undo_restores_state_and_voids_notification(): void
    {
        [$dirToken, $oldId, $subId, $session] = $this->seedScenarioSingleCampus();

        // 先做一次代課
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => '測試 Undo',
        ])->assertOk();

        // schedules 應存在 rescheduled + scheduled pair
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'rescheduled',
        ]);
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);

        // Undo
        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute/undo", []);
        $res->assertOk()
            ->assertJsonFragment([
                'class_session_id' => $session->id,
                'restored_teacher_id' => $oldId,
            ])
            ->assertJsonStructure(['voided_notification_id']);

        // schedules 已被清掉
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'rescheduled',
        ]);
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subId,
        ]);

        // LearningRecord 回復到正班
        $lr = LearningRecord::where('ClassSessionID', $session->id)->first();
        $this->assertNotNull($lr);
        $this->assertEquals($oldId, (int) $lr->TeacherID);

        // 通知 voided
        $notif = Notification::where('SourceType', 'ClassSession')->where('SourceID', $session->id)->first();
        $this->assertNotNull($notif);
        $this->assertNotNull($notif->ResolvedAt);
        $this->assertTrue((bool) ($notif->Payload['voided'] ?? false));
    }

    // ───────────────────────────────────────────────────────────
    // 4. 老師請假 Preview
    // ───────────────────────────────────────────────────────────
    public function test_teacher_leave_preview_returns_affected_sessions(): void
    {
        [$dirToken, $oldId, , $session, $campusId] = $this->seedScenarioSingleCampus();
        // 另外多建一堂同老師的課
        $sc = StudentClass::find($session->StudentClassID);
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withAuth($dirToken)->postJson('/api/v1/teacher-leaves/preview', [
            'teacher_id' => $oldId,
            'date_start' => '2026-04-19',
            'date_end' => '2026-04-25',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['sessions' => [['class_session_id', 'student_name', 'campus_id', 'session_date', 'start_time', 'end_time']], 'total', 'truncated']);
        $sessions = $res->json('sessions');
        $this->assertGreaterThanOrEqual(2, count($sessions));
        foreach ($sessions as $s) {
            $this->assertEquals($campusId, (int) $s['campus_id']);
        }
    }

    // ───────────────────────────────────────────────────────────
    // 5. 批次代課：全部成功 summary
    // ───────────────────────────────────────────────────────────
    public function test_batch_substitute_success_summary(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();
        // 再開一堂正班課（同正班老師），要一起被代課
        $sc = StudentClass::find($session->StudentClassID);
        $session2 = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $session2->id,
            'TeacherID' => $sc->TeacherID, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => '2026-04-20', 'StartTime' => '16:00', 'EndTime' => '18:00',
        ]);

        $res = $this->withAuth($dirToken)->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [
                ['class_session_id' => $session->id, 'substitute_teacher_id' => $subId, 'reason' => '請假 Day1'],
                ['class_session_id' => $session2->id, 'substitute_teacher_id' => $subId, 'reason' => '請假 Day2'],
            ],
            'atomic' => true,
        ]);

        $res->assertOk()
            ->assertJsonStructure(['summary' => ['total', 'success', 'fail', 'cross_campus'], 'results'])
            ->assertJsonFragment(['total' => 2, 'success' => 2, 'fail' => 0]);

        $this->assertEquals(2, Notification::where('Type', 'substitute')->count());
    }

    // ───────────────────────────────────────────────────────────
    // 6. 批次代課：內含衝堂 → atomic 整批回滾（422）
    // ───────────────────────────────────────────────────────────
    public function test_batch_substitute_rolls_back_on_cross_campus_conflict(): void
    {
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        // 在另一分校同時段替 $subId 建立一堂衝堂課
        $otherCampusId = 2;
        UserCampus::create(['CampusID' => $otherCampusId, 'UserID' => $subId, 'Admin' => 0, 'Approved' => 1]);
        $st = Student::create([
            'name' => '他校學生B', 'CampusID' => $otherCampusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $st->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $subId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withAuth($dirToken)->postJson('/api/v1/teacher-leaves/batch-substitute', [
            'assignments' => [
                ['class_session_id' => $session->id, 'substitute_teacher_id' => $subId],
            ],
            'atomic' => true,
        ]);

        $res->assertStatus(422)
            ->assertJsonStructure(['results' => [['index', 'ok', 'error']]]);

        // 整批回滾：無任何新通知/新 schedules
        $this->assertDatabaseMissing('Notifications', [
            'Type' => 'substitute',
            'SourceType' => 'ClassSession',
            'SourceID' => (string) $session->id,
        ]);
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $session->StudentClassID,
            'status' => 'rescheduled',
            'schedule_date' => '2026-04-19',
        ]);
    }

    // ───────────────────────────────────────────────────────────
    // 7. GET /teachers/{id}/availability 跨分校聚合
    // ───────────────────────────────────────────────────────────
    public function test_availability_returns_cross_campus_busy_slots(): void
    {
        [$dirToken, , $subId] = $this->seedScenarioSingleCampus();

        // 於另一分校再建一堂正班課
        $otherCampusId = 2;
        UserCampus::create(['CampusID' => $otherCampusId, 'UserID' => $subId, 'Admin' => 0, 'Approved' => 1]);
        $st = Student::create([
            'name' => '他校A', 'CampusID' => $otherCampusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $st->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $subId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '09:00', 'EndTime' => '10:30', 'Status' => 'scheduled',
        ]);

        // Director 需同時綁定另一分校才能查詢該老師跨分校 availability
        $dirUser = AuthToken::where('token', $dirToken)->first()->user_id;
        UserCampus::firstOrCreate(
            ['CampusID' => $otherCampusId, 'UserID' => $dirUser],
            ['Admin' => 1, 'Approved' => 1]
        );

        $res = $this->withAuth($dirToken)->getJson("/api/v1/teachers/{$subId}/availability?date=2026-04-19");

        $res->assertOk()
            ->assertJsonStructure(['teacher_id', 'date', 'busy_slots' => [['start_time', 'end_time', 'campus_id']]]);

        $slots = $res->json('busy_slots');
        $this->assertGreaterThanOrEqual(1, count($slots));
        foreach ($slots as $slot) {
            // 只應揭露 start/end/campus_id，不得夾帶學生姓名等敏感資訊
            $this->assertEqualsCanonicalizing(
                ['start_time', 'end_time', 'campus_id'],
                array_keys($slot)
            );
        }
    }

    // ───────────────────────────────────────────────────────────
    // 8. GET /substitutes/recent 分校隔離
    // ───────────────────────────────────────────────────────────
    public function test_recent_substitutes_are_scoped_to_managed_campus(): void
    {
        [$dirToken, , $subId, $session, $campusId] = $this->seedScenarioSingleCampus();

        // 建立一次代課以產生近 7 天通知
        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ])->assertOk();

        // 另一分校的主任不應看到這筆
        $otherCampusId = 99;
        $otherDir = User::create([
            'LoginName' => 'dir-other@example.com', 'Name' => '他校主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0911111111', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $otherCampusId, 'UserID' => $otherDir->id, 'Admin' => 1, 'Approved' => 1]);
        $otherToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $otherDir->id, 'token' => $otherToken, 'expires_at' => now()->addDay()]);

        $res = $this->withAuth($otherToken)->getJson('/api/v1/substitutes/recent');
        $res->assertOk();
        $this->assertEquals(0, $res->json('total'));

        // 原分校主任應看得到
        $res2 = $this->withAuth($dirToken)->getJson('/api/v1/substitutes/recent');
        $res2->assertOk();
        $this->assertGreaterThanOrEqual(1, $res2->json('total'));
        $items = $res2->json('items');
        $this->assertEquals($campusId, (int) $items[0]['campus_id']);
        $this->assertArrayHasKey('cross_campus', $items[0]);
    }

    // ───────────────────────────────────────────────────────────
    // 9. GET /system/settings/substitute-undo — 預設 5 秒，所有授權角色可讀
    // ───────────────────────────────────────────────────────────
    public function test_undo_setting_get_returns_defaults(): void
    {
        [$dirToken] = $this->seedScenarioSingleCampus();

        $res = $this->withAuth($dirToken)->getJson('/api/v1/system/settings/substitute-undo');
        $res->assertOk()
            ->assertJsonFragment([
                'undo_window_seconds' => 5,
                'default' => 5,
                'allowed_values' => [5, 10, 20, 30],
            ])
            ->assertJsonPath('server_window_seconds', 65);
    }

    // ───────────────────────────────────────────────────────────
    // 10. PUT — 僅 super_admin 可寫 & value 必須為允許清單
    // ───────────────────────────────────────────────────────────
    public function test_undo_setting_put_super_admin_only(): void
    {
        [$dirToken] = $this->seedScenarioSingleCampus();

        // director → 403
        $this->withAuth($dirToken)->putJson('/api/v1/system/settings/substitute-undo', [
            'undo_window_seconds' => 10,
        ])->assertStatus(403);

        // super_admin → 200
        $super = User::create([
            'LoginName' => 'root@example.com', 'Name' => 'root', 'PSW' => 'x',
            'type' => 'S', 'phone' => '0900000999', 'MustChangePassword' => false,
        ]);
        $superToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $super->id, 'token' => $superToken, 'expires_at' => now()->addDay()]);

        $this->withAuth($superToken)->putJson('/api/v1/system/settings/substitute-undo', [
            'undo_window_seconds' => 30,
        ])->assertOk()
          ->assertJsonFragment(['undo_window_seconds' => 30, 'server_window_seconds' => 90]);

        $this->assertEquals('30', SystemSetting::get('substitute.undo_window_seconds'));

        // 非允許值 → 422
        $this->withAuth($superToken)->putJson('/api/v1/system/settings/substitute-undo', [
            'undo_window_seconds' => 7,
        ])->assertStatus(422);
    }

    // ───────────────────────────────────────────────────────────
    // 11. substitute 回應 undo_window_seconds 隨設定即時變動；
    //     Undo 逾越伺服器窗（value+60s）後回 410。
    // ───────────────────────────────────────────────────────────
    public function test_substitute_response_reflects_undo_setting_and_expiry(): void
    {
        SystemSetting::set('substitute.undo_window_seconds', '10');
        [$dirToken, , $subId, $session] = $this->seedScenarioSingleCampus();

        $res = $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
        ]);
        $res->assertOk()->assertJsonFragment(['undo_window_seconds' => 10]);

        // 將該通知的建立時間往前推 71 秒 → 超過 10 + 60 的 server window
        $notif = \App\Models\Notification::where('SourceType', 'ClassSession')
            ->where('SourceID', $session->id)->first();
        $this->assertNotNull($notif);
        $notif->created_at = now()->subSeconds(71);
        $notif->save();

        $this->withAuth($dirToken)->postJson("/api/v1/class-sessions/{$session->id}/substitute/undo")
            ->assertStatus(410)
            ->assertJsonFragment(['server_window_seconds' => 70]);
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
            'LoginName' => 'dir-uxv2@example.com', 'Name' => '主任UXv2', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900000101', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular-uxv2@example.com', 'Name' => '正班UXv2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000102', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-uxv2@example.com', 'Name' => '代課UXv2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000103', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => 'UXv2 測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
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
