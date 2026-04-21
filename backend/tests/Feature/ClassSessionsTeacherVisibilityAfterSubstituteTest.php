<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1/B2 Regression (2026-04-21)
 *
 * Bug：代課後原老師手機登錄仍看得到被代課的課程要點名。
 *
 * 根因：`ClassSessionController::index` 在 role === 'teacher' 時用
 *   `sc.TeacherID = ? OR sub_sched.teacher_id = ?` 合併條件，
 *   代課記錄存在時第一分支仍命中契約老師，未排除。
 *
 * 修正語意：
 *   - 有代課記錄 (sub_sched.teacher_id IS NOT NULL)：僅代課老師可見
 *   - 無代課記錄：由契約老師可見
 *
 * 本測試檔直接驗證 `GET /api/v1/class-sessions` 對 teacher role 的可見性，
 * 不依賴 substitute API（避免通知／rate limit／reschedule 等副作用干擾）。
 */
class ClassSessionsTeacherVisibilityAfterSubstituteTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_teacher_cannot_see_substituted_session(): void
    {
        [$t1Token, $t2Token, $t1Id, $t2Id, $session] = $this->seedSubstitutedSession();

        // 代課記錄存在 → 原老師（契約 T1）不應看到該堂
        $resT1 = $this->withHeaders([
            'Authorization' => "Bearer {$t1Token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-05-01&end=2026-05-01&per_page=100');
        $resT1->assertOk();
        $idsT1 = collect($resT1->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertNotContains((int) $session->id, $idsT1,
            '代課後契約老師（T1）不應在 class-sessions 列表看到被代課的堂次');

        // 代課老師（T2）應該看到該堂
        $resT2 = $this->withHeaders([
            'Authorization' => "Bearer {$t2Token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-05-01&end=2026-05-01&per_page=100');
        $resT2->assertOk();
        $idsT2 = collect($resT2->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains((int) $session->id, $idsT2,
            '代課老師（T2）應可在 class-sessions 列表看到該堂');

        // 回傳 row 的 teacher_id 應指向代課老師（既有行為，保留為防回歸）
        $row = collect($resT2->json('data'))->firstWhere('id', (int) $session->id);
        $this->assertNotNull($row);
        $this->assertSame($t2Id, (int) ($row['teacher_id'] ?? 0),
            'class-sessions row.teacher_id 在有代課時必須解析為代課老師');
    }

    public function test_original_teacher_still_sees_non_substituted_session(): void
    {
        // 回歸：沒有代課記錄的堂次必須仍對契約老師可見（防止修正過度）
        [$t1Token, , $t1Id, , ] = $this->seedSubstitutedSession();

        // 另建一堂「無代課」的 ClassSession（同 T1 契約下的另一個學生課程）
        $student2 = Student::create([
            'name' => '無代課學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc2 = StudentClass::create([
            'StudentID' => $student2->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $t1Id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $cs = ClassSession::create([
            'StudentClassID' => $sc2->ID, 'SessionDate' => '2026-05-01',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$t1Token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-05-01&end=2026-05-01&per_page=100');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains((int) $cs->id, $ids,
            '無代課的堂次必須仍對契約老師可見（防止修正過度排除）');
    }

    public function test_teacher_id_query_excludes_substituted_session_for_original_teacher(): void
    {
        [, , $t1Id, $t2Id, $session, $dirToken] = $this->seedSubstitutedSession();

        // director 帶 teacher_id=T1 查衝堂 → 被代課堂不應出現（FR-001）
        $resT1 = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?teacher_id={$t1Id}&start=2026-05-01&end=2026-05-01&per_page=100");
        $resT1->assertOk();
        $idsT1 = collect($resT1->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertNotContains((int) $session->id, $idsT1,
            'teacher_id=T1 查詢不應包含被代課堂（衝堂檢查誤報修正）');

        // director 帶 teacher_id=T2 → 被代課堂應出現且 teacher_id 指向 T2
        $resT2 = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?teacher_id={$t2Id}&start=2026-05-01&end=2026-05-01&per_page=100");
        $resT2->assertOk();
        $idsT2 = collect($resT2->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains((int) $session->id, $idsT2,
            'teacher_id=T2 查詢應包含被代課堂');
        $row = collect($resT2->json('data'))->firstWhere('id', (int) $session->id);
        $this->assertSame($t2Id, (int) ($row['teacher_id'] ?? 0),
            'teacher_id query 回傳的 teacher_id 欄位應指向代課老師');
    }

    public function test_teacher_id_query_still_shows_non_substituted_session(): void
    {
        [, , $t1Id, , , $dirToken] = $this->seedSubstitutedSession();

        $student2 = Student::create([
            'name' => 'tid參數測試生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc2 = StudentClass::create([
            'StudentID' => $student2->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $t1Id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $cs = ClassSession::create([
            'StudentClassID' => $sc2->ID, 'SessionDate' => '2026-05-01',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled',
        ]);

        // director 帶 teacher_id=T1 → 無代課的堂仍出現（FR-002）
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?teacher_id={$t1Id}&start=2026-05-01&end=2026-05-01&per_page=100");
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains((int) $cs->id, $ids,
            'teacher_id=T1 查詢應包含無代課的契約堂（防過度排除）');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * 建立情境：
     *   - 分校 1
     *   - 主任（type=A）+ T1 契約老師、T2 代課老師（都是 type=T 的 User）
     *   - 學生 + 學生課程（TeacherID=T1）
     *   - ClassSession (2026-05-01 14:00)
     *   - schedules 的代課記錄 (status=scheduled, original_schedule_id=<anchor>, teacher_id=T2)
     *     + 錨點 rescheduled 列（符合實務寫入的形狀）
     *
     * @return array{0:string,1:string,2:int,3:int,4:ClassSession,5:string}
     *         [t1Token, t2Token, t1Id, t2Id, ClassSession, dirToken]
     */
    private function seedSubstitutedSession(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-sub-vis@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0910000001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $t1 = User::create([
            'LoginName' => 't1-sub-vis@example.com', 'Name' => '契約老師T1', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911001001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t1->id, 'Admin' => 0, 'Approved' => 1]);
        $t1Token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $t1->id, 'token' => $t1Token, 'expires_at' => now()->addDay()]);

        $t2 = User::create([
            'LoginName' => 't2-sub-vis@example.com', 'Name' => '代課老師T2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911002002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t2->id, 'Admin' => 0, 'Approved' => 1]);
        $t2Token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $t2->id, 'token' => $t2Token, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name' => '代課可見性測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $t1->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-05-01',
            'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled',
        ]);

        $anchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t1->id,
            'day_of_week' => 5,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => '2026-05-01',
            'student_course_id' => $sc->ID,
        ]);

        Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t2->id,
            'day_of_week' => 5,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => '2026-05-01',
            'student_course_id' => $sc->ID,
            'original_schedule_id' => $anchor->id,
        ]);

        return [$t1Token, $t2Token, (int) $t1->id, (int) $t2->id, $cs, $dirToken];
    }
}
