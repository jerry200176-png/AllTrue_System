<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-014 ~ FR-020 (自修轉到班)
 * POST /api/v1/attendance/{id}/convert-to-attended
 *
 * 防再犯紀錄 §TEST-001 ─ StudentClass NOT NULL 欄位：
 *   StudentID, GradeID, SubjectID, TeacherID, by1, TotalHours, StartDate
 */
class AttendanceConvertSelfStudyTest extends TestCase
{
    use RefreshDatabase;

    // ── AC-1: 成功轉換，StudentSingIn.Memo 清空、ClassSessionID 建立 ──────────

    public function test_director_can_convert_self_study_to_attended(): void
    {
        [$token, $student, $studentClass, $signin] = $this->scaffold();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/attendance/{$signin->id}/convert-to-attended", [
            'student_class_id' => $studentClass->ID,
        ]);

        $res->assertOk()->assertJson(['ok' => true]);

        // Memo 應清空
        $updated = StudentSignIn::find($signin->id);
        $this->assertNull($updated->Memo, 'Memo 轉換後應為 null');
        $this->assertNotNull($updated->ClassSessionID, 'ClassSessionID 應被設定');
        $this->assertEquals($studentClass->ID, $updated->StudentClassID, 'StudentClassID 應對應所選課程');
    }

    // ── AC-2: Q2 決策 — 複用同日 scheduled ClassSession ──────────────────────

    public function test_reuses_existing_scheduled_class_session_same_day(): void
    {
        [$token, $student, $studentClass, $signin] = $this->scaffold();

        // 預先建立同日同課程的 scheduled ClassSession
        $existing = ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate'    => now()->toDateString(),
            'StartTime'      => '09:00:00',
            'EndTime'        => '10:00:00',
            'Status'         => 'scheduled',
        ]);

        $before = ClassSession::count();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/attendance/{$signin->id}/convert-to-attended", [
            'student_class_id' => $studentClass->ID,
        ]);

        $res->assertOk();

        // 不應新增 ClassSession（應複用）
        $this->assertSame($before, ClassSession::count(), '應複用既有 ClassSession，不新增');

        // 複用的 session 狀態應改為 attended
        $existing->refresh();
        $this->assertEquals('attended', $existing->Status, 'ClassSession status 應改為 attended');

        // signin 的 ClassSessionID 應指向 existing
        $updated = StudentSignIn::find($signin->id);
        $this->assertEquals($existing->id, $updated->ClassSessionID, '應複用既有 ClassSession 的 id');
    }

    // ── AC-3: 非 self_study 記錄呼叫 → 422 ──────────────────────────────────

    public function test_convert_non_self_study_record_returns_422(): void
    {
        [$token, $student, $studentClass] = $this->scaffold();

        // 建立一個普通出勤記錄（非 self_study）
        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'Memo'            => null,
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/attendance/{$signin->id}/convert-to-attended", [
            'student_class_id' => $studentClass->ID,
        ]);

        $res->assertUnprocessable();
    }

    // ── AC-4: student_class_id 缺失 → 422 ────────────────────────────────────

    public function test_missing_student_class_id_returns_422(): void
    {
        [$token, $student, $studentClass, $signin] = $this->scaffold();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/attendance/{$signin->id}/convert-to-attended", []);

        $res->assertUnprocessable();
    }

    // ── AC-5: 記錄不存在 → 422 (non-self_study scope miss = 422) ─────────────

    public function test_nonexistent_signin_id_returns_422(): void
    {
        [$token] = $this->scaffold();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/attendance/999999/convert-to-attended', [
            'student_class_id' => 1,
        ]);

        $res->assertUnprocessable();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function scaffold(): array
    {
        Campus::firstOrCreate(
            ['id' => 1],
            ['name' => '測試分校', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
             'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '',
             'TeachLIFFID' => '', 'TeachLIFF_URL' => '']
        );

        $subject = Subject::firstOrCreate(
            ['Subject_Name' => '數學', 'School_id' => 1, 'Grade_no' => 0]
        );

        $director = User::create([
            'LoginName'          => 'dir-convert-' . uniqid() . '@example.com',
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0900111001',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName'          => 'tch-convert-' . uniqid() . '@example.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0911000003',
            'MustChangePassword' => false,
        ]);

        $student = Student::create([
            'name'         => '轉換測試生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);

        $studentClass = StudentClass::create([
            'StudentID'         => $student->id,
            'GradeID'           => 1,
            'SubjectID'         => $subject->id,
            'TeacherID'         => $teacher->id,
            'by1'               => 1,
            'TotalHours'        => 10,
            'StartDate'         => now(),
            'RemainingSessions' => 5,
            'SessionCount'      => 5,
        ]);

        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'Memo'            => 'self_study',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        return [$raw, $student, $studentClass, $signin];
    }
}
