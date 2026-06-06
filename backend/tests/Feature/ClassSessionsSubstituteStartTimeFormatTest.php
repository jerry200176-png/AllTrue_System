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
 * Bug Fix C1 (2026-04-21)
 *
 * Bug：代課後課程管理「單堂檢視」Modal 老師欄顯示契約老師而非代課老師；行事曆正確。
 *
 * 根因：`ClassSessionController::index` 的 sub_sched 衍生表 join 條件
 *   `sub_sched.start_time = SUBSTRING(cs.StartTime, 1, 5)`
 *   期望 schedules.start_time 為 HH:MM；若誤存為 HH:MM:SS（len=8）即比對失敗，
 *   sub_sched 為 NULL，COALESCE(subu.Name, ...) 跌回契約老師。
 *
 * 修正：join 兩側都 SUBSTRING(...,1,5)，並以 migration 標準化歷史資料。
 *
 * 測試策略：直接建立 HH:MM:SS 格式的代課 schedules 記錄，驗證 teacher_name
 * 回傳代課老師；另新增 regression case 確認 HH:MM 格式（絕大多數）不受影響。
 */
class ClassSessionsSubstituteStartTimeFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_substitute_with_hhmmss_start_time_returns_substitute_teacher_name(): void
    {
        [$dirToken, $t1Id, $t2Id, $t1Name, $t2Name, $session] = $this->seedSubstitutedSession(
            scheduleStartTime: '18:30:00', // HH:MM:SS（len=8）— 模擬 bug 資料
            scheduleEndTime: '20:30:00',
            sessionStartTime: '18:30',
            sessionEndTime: '20:30',
        );

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-05-01&end=2026-05-01&per_page=100');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', (int) $session->id);
        $this->assertNotNull($row, 'class-sessions 必須回傳該堂次');

        $this->assertSame($t2Id, (int) ($row['teacher_id'] ?? 0),
            'teacher_id 應為代課老師（HH:MM:SS 代課記錄仍必須被 join 命中）');
        $this->assertSame($t2Name, (string) ($row['teacher_name'] ?? ''),
            'teacher_name 應為代課老師姓名；若顯示契約老師即代表 C1 bug 復發');
        $this->assertNotSame($t1Name, (string) ($row['teacher_name'] ?? ''),
            'teacher_name 不應為契約老師（AC-001-a）');
    }

    public function test_hhmm_format_substitute_still_works_after_fix(): void
    {
        // Regression：確保 join 兩側都 SUBSTRING 後，HH:MM 格式（現有 79 筆正常資料）不受影響
        [$dirToken, $t1Id, $t2Id, $t1Name, $t2Name, $session] = $this->seedSubstitutedSession(
            scheduleStartTime: '18:30', // HH:MM（len=5）— 現有正常格式
            scheduleEndTime: '20:30',
            sessionStartTime: '18:30',
            sessionEndTime: '20:30',
        );

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-05-01&end=2026-05-01&per_page=100');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', (int) $session->id);
        $this->assertNotNull($row);

        $this->assertSame($t2Id, (int) ($row['teacher_id'] ?? 0),
            'HH:MM 格式代課記錄必須仍被 join 命中（AC-002-a）');
        $this->assertSame($t2Name, (string) ($row['teacher_name'] ?? ''),
            'HH:MM 格式 teacher_name 必須仍為代課老師');
    }

    public function test_no_substitute_returns_contract_teacher_name(): void
    {
        // 反向驗證（AC-001-b）：無代課記錄時不過度排除，仍顯示契約老師
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-stf-nosub@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0910000003', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $t1 = User::create([
            'LoginName' => 't1-stf-nosub@example.com', 'Name' => '契約老師NoSub', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911004004', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t1->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '無代課測試生stf', 'CampusID' => $campusId, 'ClassID' => 1,
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
            'StartTime' => '19:00', 'EndTime' => '21:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-05-01&end=2026-05-01&per_page=100');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', (int) $cs->id);
        $this->assertNotNull($row);
        $this->assertSame((int) $t1->id, (int) ($row['teacher_id'] ?? 0),
            '無代課記錄時 teacher_id 應為契約老師');
        $this->assertSame('契約老師NoSub', (string) ($row['teacher_name'] ?? ''),
            '無代課記錄時 teacher_name 應為契約老師（AC-001-b 防過度排除）');
    }

    /**
     * 建立情境：
     *   - 分校 1 + director（type=A, 有 UserCampus Admin）
     *   - T1 契約老師、T2 代課老師（都是 type=T）
     *   - 學生 + StudentClass（TeacherID=T1）
     *   - ClassSession 在 2026-05-01 StartTime/EndTime（HH:MM）
     *   - schedules 代課記錄 (status=scheduled, original_schedule_id=<anchor>, teacher_id=T2)
     *     可指定 start_time 為 HH:MM 或 HH:MM:SS 格式
     *
     * @return array{0:string,1:int,2:int,3:string,4:string,5:ClassSession}
     *         [dirToken, t1Id, t2Id, t1Name, t2Name, ClassSession]
     */
    private function seedSubstitutedSession(
        string $scheduleStartTime,
        string $scheduleEndTime,
        string $sessionStartTime,
        string $sessionEndTime,
    ): array {
        $campusId = 1;
        $uniq = bin2hex(random_bytes(4));

        $director = User::create([
            'LoginName' => "dir-stf-{$uniq}@example.com", 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '09100000' . random_int(10, 99), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $t1Name = '契約老師' . $uniq;
        $t1 = User::create([
            'LoginName' => "t1-stf-{$uniq}@example.com", 'Name' => $t1Name, 'PSW' => 'x',
            'type' => 'T', 'phone' => '09110010' . random_int(10, 99), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t1->id, 'Admin' => 0, 'Approved' => 1]);

        $t2Name = '代課老師' . $uniq;
        $t2 = User::create([
            'LoginName' => "t2-stf-{$uniq}@example.com", 'Name' => $t2Name, 'PSW' => 'x',
            'type' => 'T', 'phone' => '09110020' . random_int(10, 99), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t2->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '代課格式測試生' . $uniq, 'CampusID' => $campusId, 'ClassID' => 1,
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
            'StartTime' => $sessionStartTime, 'EndTime' => $sessionEndTime, 'Status' => 'scheduled',
        ]);

        $anchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t1->id,
            'day_of_week' => 5,
            'start_time' => $sessionStartTime, // anchor 用 HH:MM（rescheduled 不影響 sub_sched 的 join）
            'end_time' => $sessionEndTime,
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => '2026-05-01',
            'student_course_id' => $sc->ID,
        ]);

        // 關鍵：代課記錄 start_time 的格式由參數指定（測試點：HH:MM:SS 是否仍被 join 命中）
        // 使用 raw query 繞過 Eloquent 可能的自動時間正規化，確保 DB 實際儲存指定的字串長度
        $now = now();
        $scheduleData = [
            'student_id' => $student->id,
            'teacher_id' => $t2->id,
            'day_of_week' => 5,
            'start_time' => $scheduleStartTime, // 測試重點
            'end_time' => $scheduleEndTime,
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => '2026-05-01',
            'student_course_id' => $sc->ID,
            'original_schedule_id' => $anchor->id,
            'duration_hours' => 2,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        \Illuminate\Support\Facades\DB::table('schedules')->insert($scheduleData);

        return [$dirToken, (int) $t1->id, (int) $t2->id, $t1Name, $t2Name, $cs];
    }
}
